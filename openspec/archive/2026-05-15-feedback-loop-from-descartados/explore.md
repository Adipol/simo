# Exploration: feedback-loop-from-descartados

**Phase**: explore
**Date**: 2026-05-13
**Change**: feedback-loop-from-descartados
**Mode**: hybrid

---

## Current State

### Descartados data (Q1 — volume estimate)

No live DB access from localhost. Based on prior sessions:
- Session 2026-05-11: 102 rows total after backfill
- ~3 days of scraping since → estimated **150–250 rows total** (conservative)
- Operator discards ~36% based on prior conversation → estimated **54–90 descartados**
- Gemini analysis already runs on ALL rows before they appear in the bandeja → virtually all descartados have `gemini_analyzed = true`, `gemini_confianza`, `gemini_categoria`, `gemini_motivo`

⚠️ **FLAG**: Volume is low today. The report is technically feasible but will show noisy percentages on tiny N. Must add minimum-sample guards (N ≥ 5 per keyword for inclusion). Validate real numbers at apply phase via tinker before writing migrations.

### Existing columns available (no new schema needed)

From `resultados_scraping` schema (migrations):
- `descartado` boolean — indexed (`2026_03_03_194759_add_descartado_to_resultados_scraping_table.php:16-17`)
- `keyword` varchar(200) — btree index (`0001_01_01_000005_create_resultados_scraping_table.php:31`)
- `sitio_id` FK → `sitios_web.nombre` — no index on `sitio_id` specifically (only via FK)
- `categoria` varchar(20) — indexed (`0001_01_01_000005:33`)
- `gemini_analyzed` boolean — indexed (`2026_04_05_000001:25`)
- `gemini_confianza` tinyint — NO dedicated index
- `gemini_categoria` varchar(10) — indexed (`2026_04_05_000001:26`)
- `fecha_encontrado` timestamp — indexed (`0001_01_01_000005:34`)
- `relevante` boolean nullable — NO dedicated index

**Missing index**: `sitio_id` has no standalone btree. A JOIN-heavy GROUP BY on `sitio_id` will use a seq scan at low volume (fine now) but will need an index at scale.

### What's consumed by descartados today

`app/Livewire/Scraper/Resultados.php:124-130` — `descartar()` sets `descartado=true, leido=true`. No other logic.
`app/Services/ResultadoScrapingQueryService.php:71-75` — filter: `filtroDescartado='0'` hides them from bandeja.
`scripts/scraper_v2.2/core/database.py:463-471` — `url_descartada()` blacklists URL in Python scraper.

Everything else (keyword, sitio, gemini data) is wasted signal.

---

## Q2: Existing infrastructure to reuse

### Commands (pattern to mirror)

- `app/Console/Commands/LimpiarLogs.php` — best pattern: service-delegate + `$this->table()` output. Short handle(), business logic in model/service.
- `app/Console/Commands/AnalizarGemini.php` — options pattern: `{--flash-only}`, `{--pro-only}`. Good reference for `--dias`, `--keyword`, `--categoria` flags.
- `app/Console/Commands/BackfillZombieResultados.php` — `{--dry-run}` pattern, plain service + output.

**Recommended pattern for T1**: Mirror `LimpiarLogs` structure — delegate to a new `DescartadosAnalisisService`, output with `$this->table()` and `$this->info()`. ~40 lines in the command itself.

### Dashboard services

`app/Services/Dashboard/DashboardMetricsService.php` — full metrics engine with:
- Cache layer via `Cache::remember()` (TTL 300s by default)
- `resolveFilters()` for date_range/pais/categoria normalization
- `dateTruncMonth()` helper for cross-driver date grouping (pgsql vs SQLite)
- `computePrecisionMetrics()` (line 236) already has bucket logic for `gemini_confianza` ranges

**KEY FINDING**: `DashboardMetricsService::computePrecisionMetrics()` (line 236-300) queries `clasificaciones_feedback` table — this is explicit human feedback via feedback buttons. The descartados analysis is a DIFFERENT signal: implicit negative labels via the discard button. Do NOT conflate these two sources. The new service (`DescartadosAnalisisService`) must be a sibling, not an extension of `DashboardMetricsService`.

`app/Services/Dashboard/DashboardSummaryService.php` — uses `DashboardCacheManager` + cache busting. Good cache pattern to replicate.

### ResultadoScrapingQueryService

`app/Services/ResultadoScrapingQueryService.php` — builds the bandeja query. Already has `filtroDescartado` logic (line 71-75). For T1/T2 we do NOT extend this — the analysis queries are aggregation-oriented, not row-fetching. Create a sibling: `app/Services/DescartadosAnalisisService.php`.

### Charting library — CONFIRMED

`resources/views/livewire/dashboard.blade.php:43`:
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
```

Chart.js 4.4.1 is **already loaded** via CDN in the dashboard layout. No new dependency needed for T2 if the precision dashboard is a tab/section inside the existing dashboard. If it's a standalone route at `/admin/precision`, the Chart.js CDN must be added to that layout too (one line).

The dashboard also uses native SVG for the LATAM heatmap (`HeatmapPalette.php`) and sparklines. Both approaches coexist fine.

---

## Q3: SQL design for metrics

All queries assume `WHERE descartado = true AND gemini_analyzed = true` as the base filter.
The existing `descartado` btree index (line 16-17 of the migration) covers this.

### Precision general

```sql
SELECT
  COUNT(*) FILTER (WHERE relevante = true) AS relevantes,
  COUNT(*) FILTER (WHERE descartado = true) AS descartados,
  COUNT(*) AS total,
  ROUND(
    COUNT(*) FILTER (WHERE relevante = true)::numeric
    / NULLIF(COUNT(*) FILTER (WHERE relevante = true OR descartado = true), 0) * 100
  , 1) AS precision_pct
FROM resultados_scraping
WHERE gemini_analyzed = true
  AND fecha_encontrado >= NOW() - INTERVAL '30 days';
```

Note: `relevante = true` rows and `descartado = true` rows are the two labeled classes. Rows with `relevante = null` are unlabeled — exclude from precision calc.

### Per-keyword breakdown

```sql
SELECT
  keyword,
  COUNT(*) AS total,
  COUNT(*) FILTER (WHERE descartado = true) AS descartados,
  COUNT(*) FILTER (WHERE relevante = true) AS relevantes,
  ROUND(COUNT(*) FILTER (WHERE descartado = true)::numeric / COUNT(*) * 100, 1) AS pct_descartado
FROM resultados_scraping
WHERE gemini_analyzed = true
GROUP BY keyword
HAVING COUNT(*) >= 5  -- minimum sample guard
ORDER BY pct_descartado DESC;
```

### Per-sitio breakdown

```sql
SELECT
  rs.sitio_id,
  sw.nombre AS sitio_nombre,
  COUNT(*) AS total,
  COUNT(*) FILTER (WHERE rs.descartado = true) AS descartados,
  ROUND(COUNT(*) FILTER (WHERE rs.descartado = true)::numeric / COUNT(*) * 100, 1) AS pct_descartado
FROM resultados_scraping rs
JOIN sitios_web sw ON rs.sitio_id = sw.id
WHERE rs.gemini_analyzed = true
GROUP BY rs.sitio_id, sw.nombre
HAVING COUNT(*) >= 3
ORDER BY pct_descartado DESC
LIMIT 10;
```

### Drift (30d vs 30-60d)

```sql
WITH current_period AS (
  SELECT keyword,
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE descartado = true) AS descartados
  FROM resultados_scraping
  WHERE gemini_analyzed = true
    AND fecha_encontrado >= NOW() - INTERVAL '30 days'
  GROUP BY keyword
),
prev_period AS (
  SELECT keyword,
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE descartado = true) AS descartados
  FROM resultados_scraping
  WHERE gemini_analyzed = true
    AND fecha_encontrado BETWEEN NOW() - INTERVAL '60 days' AND NOW() - INTERVAL '30 days'
  GROUP BY keyword
)
SELECT
  c.keyword,
  ROUND(c.descartados::numeric / NULLIF(c.total, 0) * 100, 1) AS pct_actual,
  ROUND(p.descartados::numeric / NULLIF(p.total, 0) * 100, 1) AS pct_anterior,
  ROUND(
    (c.descartados::numeric / NULLIF(c.total, 0)
    - p.descartados::numeric / NULLIF(p.total, 0)) * 100
  , 1) AS drift_ppt
FROM current_period c
LEFT JOIN prev_period p ON c.keyword = p.keyword;
```

### Gemini confianza buckets vs descartado rate

```sql
SELECT
  CASE
    WHEN gemini_confianza BETWEEN 0  AND 49  THEN '0-49'
    WHEN gemini_confianza BETWEEN 50 AND 69  THEN '50-69'
    WHEN gemini_confianza BETWEEN 70 AND 84  THEN '70-84'
    WHEN gemini_confianza BETWEEN 85 AND 100 THEN '85-100'
  END AS bucket,
  COUNT(*) AS total,
  COUNT(*) FILTER (WHERE descartado = true) AS descartados,
  ROUND(COUNT(*) FILTER (WHERE descartado = true)::numeric / COUNT(*) * 100, 1) AS pct_descartado
FROM resultados_scraping
WHERE gemini_analyzed = true AND gemini_confianza IS NOT NULL
GROUP BY 1
ORDER BY 1;
```

**Performance estimate**: With 200–400 rows all these are trivial (full table scan is fine). At 10K rows, the existing indexes on `descartado`, `keyword`, `fecha_encontrado` will be used. At 100K rows, consider a composite partial index: `CREATE INDEX ON resultados_scraping (keyword, fecha_encontrado) WHERE descartado = true`. At 1M+ rows, consider a materialized summary view refreshed nightly.

### Missing index: sitio_id

`sitio_id` has no standalone btree. For the per-sitio GROUP BY join, add one migration:
```sql
CREATE INDEX resultados_scraping_sitio_id_idx ON resultados_scraping (sitio_id);
```
Low risk, 1 migration, needed even at current volume for join planning.

---

## Q4: Charting decision

**Decision**: Use Chart.js 4.4.1 (already loaded, line 43 dashboard.blade.php).

If T2 is a section of the existing dashboard → zero work, Chart.js is already there.
If T2 is a standalone `/admin/precision` route → add the CDN `<script>` tag to that layout.

Do NOT introduce ApexCharts, D3, or any new library. The project has established Chart.js as the charting standard.

**Alpine.js + Chart.js pattern** (already established in dashboard.blade.php lines 45-94):
```js
function precisionChart(data) {
  return {
    chart: null,
    init() { this.render(data); },
    render(data) { /* ... */ },
  };
}
```

---

## Q5: Permissions

`app/Livewire/Scraper/Resultados.php:139` — `archivar()` uses `$this->authorize('gestionar resultados')`.
`database/seeders/RolesPermisosSeeder.php`:
- `admin` + `supervisor` have `gestionar resultados`
- `operador` does NOT

**Recommendation**:
- `simo:analizar-descartados` (T1): protect with the same permission `gestionar resultados`. Artisan commands can use `$this->output` + Gate check, or simply document it's admin-only in the command description.
- T2 dashboard view: use `$this->authorize('ver dashboard estadisticas')` — already used in `Dashboard.php:67`. Admin + supervisor have this. Operador does not.
- No new permissions needed. Reuse existing.

---

## Q6: Refresh frequency for T2 dashboard

Recommendation: **`wire:poll.300s`** (every 5 minutes).

Rationale:
- Descartados data changes only when the operator actively works the bandeja — not real-time
- The existing dashboard already polls summary every 30s, health every 15s, discoveries every 60s
- 5-minute refresh is a good balance: fresh enough to feel live, light on DB
- Cache the service results with 5-minute TTL to match poll frequency

Alternative: on-demand only (`wire:click="refrescar"`) — simpler, but less "live" feel.

---

## Q7: Empty / sparse data handling

Rules for the service and command:
1. **Global minimum**: require at least 10 total labeled rows (relevante=true OR descartado=true) before computing precision%. Below that, show "Datos insuficientes — seguí usando la bandeja para acumular etiquetas."
2. **Per-keyword minimum**: N ≥ 5 total to appear in the report. Below 5, aggregate into "Otros keywords (N < 5)".
3. **Per-sitio minimum**: N ≥ 3 total to appear.
4. **Drift**: only show drift rows where both periods have N ≥ 3. Otherwise mark drift as "N/D".
5. **Percentages**: always show N alongside the %. Never show "100% descartado" without "(N=1)".

Command output when insufficient data:
```
⚠ Datos insuficientes (X descartados con Gemini análisis).
Se necesitan al menos 10 artículos etiquetados para generar el reporte.
Seguí usando la bandeja para acumular feedback humano.
```

---

## Q8: T3 hooks (design seam for future auto-feedback)

The new `DescartadosAnalisisService` MUST expose this method signature so T3 can call it directly without query duplication:

```php
/**
 * Returns top N descartados rows as negative example candidates.
 * High confidence + descartado = wrong Gemini guess, strong negative signal.
 *
 * @return Collection<int, ResultadoScraping>
 */
public function getNegativeExamples(int $limit = 10): Collection
{
    return ResultadoScraping::where('descartado', true)
        ->where('gemini_analyzed', true)
        ->where('gemini_confianza', '>=', 70) // high-confidence wrong guesses
        ->whereNotNull('gemini_motivo')
        ->orderByDesc('gemini_confianza')
        ->limit($limit)
        ->get(['id', 'keyword', 'titulo', 'contexto', 'gemini_motivo', 'gemini_categoria']);
}
```

T3 (future SDD) will import `DescartadosAnalisisService` and call `getNegativeExamples()` to inject into the Gemini prompt as few-shot negative examples.

---

## Q9: Tests strategy

### Existing patterns to follow

`tests/Feature/Commands/BackfillZombieResultadosTest.php` — gold standard for command tests:
- `RefreshDatabase` + manual record creation via `ResultadoScraping::create()` (not factory)
- `ResultadoScraping::flushEventListeners()` before creates
- `$this->artisan('command:name')->assertSuccessful()`
- Assert on output strings with `->expectsOutput()` or `->expectsTable()`

`tests/Feature/Livewire/DashboardEstadisticasTest.php` — gold standard for Livewire stats tests:
- `Livewire::actingAs($user)->test(Component::class)`
- `->call('method')` + `->assertForbidden()` for auth
- `->assertSet('property', value)` for state

`tests/Feature/View/Dashboard/ActionLayerTest.php` — Blade component tests via `view()->render()`.

### Plan for feedback-loop-from-descartados tests

**T1 command tests** (`tests/Feature/Commands/AnalizarDescartadosTest.php`):
- `test_command_outputs_no_data_message_when_zero_descartados`
- `test_command_shows_keyword_breakdown_with_sufficient_data`
- `test_command_filters_by_dias_option`
- `test_command_filters_by_keyword_option`
- `test_command_filters_by_categoria_option`
- `test_command_excludes_keywords_below_minimum_sample`
- `test_command_shows_recommendations_when_keyword_exceeds_threshold`

**T2 Livewire tests** (`tests/Feature/Livewire/PrecisionDashboardTest.php`):
- `test_admin_can_view_precision_dashboard`
- `test_operador_cannot_view_precision_dashboard`
- `test_dashboard_shows_insufficient_data_message_when_empty`
- `test_dashboard_computes_keyword_breakdown`
- `test_dashboard_auto_refreshes_with_wire_poll`

**Service unit tests** (`tests/Feature/Services/DescartadosAnalisisServiceTest.php`):
- `test_precision_general_with_known_data`
- `test_per_keyword_excludes_below_minimum`
- `test_drift_calculation_compares_periods`
- `test_confianza_buckets_grouped_correctly`
- `test_get_negative_examples_returns_high_confidence_descartados`

---

## Q10: Performance at scale

| Volume | Strategy |
|--------|----------|
| < 10K rows | Current btree indexes sufficient, no changes |
| 10K–100K rows | Add composite partial index: `(keyword, fecha_encontrado) WHERE descartado = true` |
| 100K–1M rows | Add `(sitio_id, fecha_encontrado) WHERE descartado = true`; consider caching aggregates with 1h TTL |
| > 1M rows | Materialized view `mv_descartados_metrics` refreshed nightly via scheduler |

Current estimate: 200–400 rows. No performance concern for 12–24 months at current growth rate.

---

## Affected Areas

- `app/Console/Commands/AnalizarDescartados.php` — NEW (T1): ~40 lines, delegates to service
- `app/Services/DescartadosAnalisisService.php` — NEW: core query engine for T1 and T2
- `app/Services/Dashboard/DTOs/DescartadosMetricsDTO.php` — NEW: typed output container
- `app/Livewire/Admin/PrecisionDashboard.php` — NEW (T2): Livewire component for the view
- `resources/views/livewire/admin/precision-dashboard.blade.php` — NEW (T2): view with Chart.js
- `database/migrations/{date}_add_sitio_id_idx_to_resultados_scraping.php` — NEW: missing index
- `tests/Feature/Commands/AnalizarDescartadosTest.php` — NEW
- `tests/Feature/Services/DescartadosAnalisisServiceTest.php` — NEW
- `tests/Feature/Livewire/PrecisionDashboardTest.php` — NEW
- `routes/web.php` — ADD route `/admin/precision` for T2

**NOT touched**:
- `app/Livewire/Scraper/Resultados.php` — no changes to UX
- `app/Services/ResultadoScrapingQueryService.php` — no changes
- Python scraper — not touched
- `resultados_scraping` schema — no new columns (only one new index)

---

## Approaches

### Approach A — Tab inside existing dashboard (T2 as widget)

Add the precision metrics as a new collapsible section within the existing `Dashboard.php` Livewire component, similar to `mostrarEstadisticas`.

- **Pros**: Zero new route, Chart.js already loaded, consistent UX, one fewer Livewire component to maintain
- **Cons**: Dashboard.php growing large (already 243 lines), mixes two conceptually different concerns
- **Effort**: Low

### Approach B — Standalone Livewire page at `/admin/precision` (recommended)

New `PrecisionDashboard` Livewire full-page component with its own route.

- **Pros**: Clean separation, own polling cycle, own URL (linkable), `DescartadosAnalisisService` stays fully decoupled, easier to test in isolation
- **Cons**: Need to add Chart.js CDN to layout (one line), one more route
- **Effort**: Low-Medium

### Approach C — Extend DashboardMetricsService

Add descartado-flavored methods directly to `DashboardMetricsService`.

- **Pros**: Reuses existing cache infrastructure
- **Cons**: Conflates two semantically different precision signals (explicit feedback vs implicit discards); violates SRP; harder to reuse for T3; not recommended
- **Effort**: Low, but wrong

---

## Recommendation

**Approach B**: standalone Livewire page at `/admin/precision`.

Reasons:
1. Keeps `DescartadosAnalisisService` fully focused (one domain = one service)
2. The `getNegativeExamples()` seam for T3 is cleanly owned by this service
3. Consistent with project pattern: `gestionar resultados` permission already scopes admin/supervisor
4. Own URL means the operator can bookmark it
5. Chart.js CDN addition is trivial

**T1 command** is independent of T2 — implement first, validate the queries are correct, then build the dashboard on top of the same service.

For the route: `/admin/precision` maps to `PrecisionDashboard` component, protected by `gestionar resultados` middleware.

---

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Low data volume → noisy initial reports | **Medium** | Minimum sample guards (N≥5 keyword, N≥3 sitio, N≥10 global). Display "insuficientes datos" message explicitly. |
| `descartar()` has no `authorize()` call in Resultados.php:124 | **Low** | Out of scope for this SDD; note it as a debt item |
| `sitio_id` missing index slows JOIN at scale | **Low** | Add the migration in this SDD; one line |
| Precision metric can be misleading if `relevante` is rarely set | **Medium** | Document in the report that "precision real" requires both labels. Track `relevante = null` rows separately as "sin etiquetar". |
| T2 polling (5min) vs cache TTL mismatch | **Low** | Set cache TTL = poll interval. `DashboardCacheManager` pattern already handles this cleanly. |
| Confusion between `clasificaciones_feedback` precision and descartados precision | **Medium** | Name the service `DescartadosAnalisisService` (not `PrecisionService`) and clearly label both signals in the UI. |

---

## Open Questions

1. **T2 placement**: standalone `/admin/precision` page (Approach B, recommended) or widget inside the existing dashboard? The orchestrator should confirm with the user before propose.

2. **Minimum sample thresholds**: N≥5 for keywords, N≥10 global — are these the right values for the operator's use case? With 54–90 descartados today, some keywords may always be below threshold.

3. **Drift window**: 30d vs 30-60d. If the system is only a few months old, the "previous period" may have zero data. Should drift be computed week-over-week instead?

4. **`descartar()` missing `authorize()` check** (line 124, `Resultados.php`): is this intentional (all authenticated users can discard) or a debt item to fix?

5. **Real production count**: before apply phase, validate with tinker: how many rows total, how many descartados, how many have `gemini_confianza IS NOT NULL`. If the count is < 20 descartados, consider delaying T2 until there's more data.

---

## Ready for Proposal

**Yes** — with the caveat that Q1 (T2 placement) should be confirmed with the user before committing to the route structure. All other questions are answerable by convention or can be decided during propose.

---

## Result Contract

```json
{
  "status": "complete",
  "executive_summary": [
    "~54–90 descartados estimated in prod today; all carry full Gemini metadata (confianza, motivo, categoria) making them usable as labeled negative examples",
    "No new DB columns needed — all analysis runs on existing schema; only one new index (sitio_id btree) required",
    "Chart.js 4.4.1 already loaded in dashboard.blade.php:43 — T2 charts cost zero new dependencies",
    "DashboardMetricsService already has a precision-by-bucket pattern to mirror (line 236-300), but the new service must be a sibling not an extension — different signal source",
    "Recommended: Approach B (standalone /admin/precision Livewire page) + DescartadosAnalisisService sibling + getNegativeExamples() seam for future T3",
    "Low data volume today is the primary risk — minimum sample guards (N≥5 keyword, N≥10 global) are mandatory to avoid misleading percentages"
  ],
  "artifacts": {
    "engram_topic_key": "sdd/feedback-loop-from-descartados/explore",
    "file_path": "openspec/changes/feedback-loop-from-descartados/explore.md"
  },
  "next_recommended": "sdd-propose",
  "risks": [
    {"severity": "medium", "description": "Low data volume (54-90 descartados) makes initial reports noisy — minimum sample guards are mandatory"},
    {"severity": "medium", "description": "Confusion between clasificaciones_feedback precision vs descartados precision — two different signals that must be clearly named and separated"},
    {"severity": "low", "description": "sitio_id has no standalone btree index — add a migration"},
    {"severity": "low", "description": "descartar() (Resultados.php:124) has no authorize() gate — pre-existing debt, document as separate issue"},
    {"severity": "low", "description": "Drift window may show empty previous period if system is young — handle gracefully with N/D label"}
  ],
  "open_questions": [
    "T2 placement: standalone /admin/precision route vs widget inside existing dashboard?",
    "Minimum N thresholds acceptable to the operator (N≥5 keyword, N≥10 global)?",
    "Drift window: 30d-vs-60d or weekly?",
    "Confirm real descartados count via tinker before starting apply phase"
  ]
}
```
