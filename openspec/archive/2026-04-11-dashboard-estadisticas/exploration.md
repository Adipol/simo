# Exploration: dashboard-estadisticas

## Current State

### 1. Existing Dashboard

**Status**: A dashboard stub already exists at `/dashboard` via `App\Livewire\Dashboard` and `resources/views/livewire/dashboard.blade.php`.

**What it shows today**:
- KPI cards: resultados hoy (with PEP/OPI breakdown), sin leer, cambios PEP sin revisar, sitios/fuentes
- Script status panels for Scraper + PEP Monitor (ejecutando indicator, last execution info)
- Two lists: últimos resultados scraper + últimos cambios PEP (5 each)

**Landing page**: After login, user lands on `/dashboard` (Dashboard component). This is the same page that will host the new statistics widgets.

**Layout used**: `layouts.app` with 60px fixed sidebar (dark theme) + topbar + main content area. All existing components follow this same layout via `#[Layout('layouts.app')]`.

### 2. Tailwind Setup

Tailwind 3.1 (not 4 — conflicting doc in task vs actual `tailwind.config.js`). The task says "Tailwind 4" but the project has `tailwindcss: "^3.1.0"` in package.json and `import tailwindcss` is NOT in app.css (it's the old `@tailwind` directive approach). The task mentions "no `var()` in className, semantic classes" which is a Tailwind 4 pattern, but this project is still on Tailwind 3. I'll follow the ACTUAL project setup (Tailwind 3, `@tailwind` directive, no `var()` pattern to worry about).

CSS custom components in `resources/css/app.css`:
- `.simo-card` — white bg, rounded-xl, border-zinc-200, p-6
- `.simo-badge` — inline-flex, small, rounded-full
- `.simo-btn`, `.simo-btn-primary`, `.simo-btn-ghost`
- `.simo-input`, `.simo-select`
- `.simo-table` — full width, styled thead/tbody

No chart library in package.json. Chart.js is not bundled. The project currently has no charting capability at all.

### 3. Data Models Available

**ResultadoScraping** (`resultados_scraping` table):
- Key fields: `pais`, `categoria`, `gemini_analyzed`, `gemini_is_pep`, `gemini_categoria`, `gemini_nombre`, `gemini_cargo`, `gemini_confianza` (0-100), `gemini_entidad_tipo` (via later migration), `fecha_encontrado`, `leido`, `relevante`, `descartado`
- Indexes: `gemini_analyzed`, `gemini_categoria`
- Relationships: `sitio`, `pais` (belongsTo), `feedback` (hasMany)

**ClasificacionFeedback** (`clasificaciones_feedback` table):
- Key fields: `resultado_scraping_id`, `usuario_id`, `tipo` (enum: correcto/incorrecto), `clasificacion_snapshot` (JSON), `corregido_is_pep`, `corregido_categoria`, `corregido_nombre`, `corregido_cargo`, `motivo`
- Indexes: `tipo`, `usuario_id`
- Unique constraint on (resultado_scraping_id, usuario_id)
- Relationships: `resultadoScraping`, `usuario`

**CargoPep** (`cargos_pep`): has `entidad_tipo` field (via lematizacion-pep-opi)

**EntidadPublica** (`entidades_publicas`): empty currently (Bolivia seeder was a stub)

**Pais** (`paises`): `codigo` (string PK), `nombre`, `activo`

### 4. Permission Model

From `RolesPermisosSeeder.php`:
- `ver dashboard` — all three roles (admin, supervisor, operador) have this
- `dar feedback clasificaciones` — admin + supervisor (NOT operador)
- Existing dashboard is visible to all authenticated users

For the statistics dashboard, I'll recommend a new permission `ver dashboard estadisticas` restricted to admin + supervisor (operador reads operational data but doesn't need system-wide precision metrics).

### 5. Script Status Existing Pattern

The `Estado.php` Livewire component already demonstrates:
- `wire:poll.10s` for auto-refresh
- Alpine.js for real-time progress bars
- Conditional badge rendering
- Grid layouts with simo-card
- Tables with simo-table class

This is the established pattern for dashboard-like pages.

---

## Affected Areas

- `app/Livewire/Dashboard.php` — ADD new statistics methods, new data computed properties
- `resources/views/livewire/dashboard.blade.php` — EXTEND with new statistics widgets below existing content (or REPLACE existing with unified dashboard — TBD in design phase)
- `routes/web.php` — no new routes needed (statistics is the same `/dashboard` route, just richer)
- `database/seeders/RolesPermisosSeeder.php` — ADD new permission `ver dashboard estadisticas`
- `app/Services/DashboardMetricsService.php` — NEW service for aggregation queries (keeps component thin)
- `app/DTOs/` — NEW DTOs for widget data transfer

---

## Data Inventory: Computable Metrics

### Volume Metrics (from `resultados_scraping`)

| Metric | Query |
|--------|-------|
| Total PEPs detected | `WHERE gemini_analyzed = true AND gemini_is_pep = true AND gemini_categoria = 'PEP'` |
| Total OPIs detected | `WHERE gemini_analyzed = true AND gemini_is_pep = true AND gemini_categoria = 'OPI'` |
| Total analyzed | `WHERE gemini_analyzed = true` |
| Volume by month | `GROUP BY DATE_TRUNC('month', fecha_encontrado)` |
| Volume by week | `GROUP BY DATE_TRUNC('week', fecha_encontrado)` |
| Volume by country | `GROUP BY pais` |
| Today's volume | `WHERE DATE(fecha_encontrado) = CURRENT_DATE` |
| PEPs this month vs last month | Compare two `WHERE DATE_TRUNC('month', fecha_encontrado) = X` counts |

### Precision Metrics (from `clasificaciones_feedback` + `resultados_scraping`)

| Metric | Query |
|--------|-------|
| Overall accuracy rate | `COUNT(tipo = 'correcto') / COUNT(*) * 100` across all feedback |
| Precision by cargo | `JOIN resultados_scraping ON feedback.resultado_scraping_id = resultados.id GROUP BY gemini_cargo` → correct/incorrect counts |
| Precision by country | Same JOIN, GROUP BY pais |
| Precision by confidence bucket | `CASE WHEN gemini_confianza BETWEEN 0 AND 50 THEN '0-50' WHEN 51-80 THEN '51-80' ELSE '81-100' END` |
| False positive rate | `COUNT(tipo = 'incorrecto' AND corrigio_is_pep = false AND original gemini_is_pep = true) / COUNT(gemini_is_pep = true)` |
| Feedbacks this week vs last week | Compare weekly counts |

### Top Failing Positions

```sql
SELECT gemini_cargo,
       COUNT(*) as total_feedbacks,
       COUNT(CASE WHEN tipo = 'incorrecto' THEN 1 END) as errores,
       ROUND(COUNT(CASE WHEN tipo = 'incorrecto' THEN 1 END)::numeric / COUNT(*) * 100, 1) as error_rate
FROM clasificaciones_feedback fb
JOIN resultados_scraping rs ON fb.resultado_scraping_id = rs.id
WHERE gemini_cargo IS NOT NULL
GROUP BY gemini_cargo
HAVING COUNT(*) >= 3  -- minimum sample size
ORDER BY error_rate DESC
LIMIT 10;
```

### Geographic Distribution

```sql
SELECT pais,
       COUNT(CASE WHEN gemini_is_pep = true AND gemini_categoria = 'PEP' THEN 1 END) as peps,
       COUNT(CASE WHEN gemini_is_pep = true AND gemini_categoria = 'OPI' THEN 1 END) as opis,
       ROUND(AVG(gemini_confianza), 1) as avg_confianza,
       COUNT(CASE WHEN fb.tipo = 'incorrecto' THEN 1 END)::numeric /
         NULLIF(COUNT(*), 0) * 100 as error_rate
FROM resultados_scraping rs
LEFT JOIN clasificaciones_feedback fb ON rs.id = fb.resultado_scraping_id
WHERE gemini_analyzed = true
GROUP BY pais
ORDER BY peps DESC;
```

### Recent Activity

```sql
-- Last 10 high-confidence PEPs
SELECT titulo, gemini_nombre, gemini_cargo, pais, gemini_confianza, fecha_encontrado
FROM resultados_scraping
WHERE gemini_analyzed = true AND gemini_is_pep = true AND gemini_categoria = 'PEP'
  AND gemini_confianza >= 90
ORDER BY fecha_encontrado DESC
LIMIT 10;

-- Latest user corrections
SELECT fb.*, rs.gemini_cargo, rs.gemini_confianza, u.name as usuario
FROM clasificaciones_feedback fb
JOIN resultados_scraping rs ON fb.resultado_scraping_id = rs.id
JOIN users u ON fb.usuario_id = u.id
ORDER BY fb.created_at DESC
LIMIT 10;
```

### Trend Indicators

| Indicator | Logic |
|-----------|-------|
| PEPs this month vs last | Compare two monthly counts → percentage change |
| OPIs this month vs last | Same |
| Feedback this week vs last | Compare two weekly counts |
| Unread ratio | `resultados_sin_leer / total_resultados * 100` |

---

## Chart Library Decision

**Decision**: Chart.js via vanilla JavaScript with `wire:ignore` on chart containers.

**Rationale**:
- No chart library currently in project (no Chart.js, no ApexCharts, no anything)
- Livewire and charts are notoriously problematic together (Livewire re-renders wipe canvas)
- Chart.js is the simplest, most stable option for this pattern
- Installing a new chart library adds weight; Chart.js is ~60KB gzipped which is acceptable
- Alpine.js already in project for interactivity

**Alternative considered**: Custom SVG-based charts (no dependency). Rejected because:
- Time to build properly is significant
- Bar/line charts need significant code for a production-quality look
- Chart.js handles animations, tooltips, legend properly

**Pattern to implement**:
```blade
<div x-data="chartWidget()" wire:ignore>
    <canvas id="volumeChart"></canvas>
</div>

<script>
function chartWidget() {
    return {
        init() {
            fetch('/api/dashboard/metrics')
                .then(r => r.json())
                .then(data => this.renderChart(data));
        },
        renderChart(data) {
            new Chart(document.getElementById('volumeChart'), {
                type: 'bar',
                data: { ... },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });
        }
    }
}
</script>
```

Or better: use a dedicated metrics JSON endpoint that returns all computed data, then Alpine fetches and renders.

---

## UI Layout Proposals

### Variant A: Minimal (extend existing dashboard)

**Approach**: Append statistics widgets below the existing dashboard content on the same `/dashboard` page.

```
┌─────────────────────────────────────────────────────────────┐
│ [Existing KPI Cards]  [Script Status]  [Recent Activity]   │
├─────────────────────────────────────────────────────────────┤
│ NEW: Filter Bar (date range, country, category)              │
├─────────────────────────────────────────────────────────────┤
│ NEW: System Precision Card    │ NEW: Volume Trend Card      │
│ (accuracy %, precision stats)  │ (line chart: PEPs over time│
├─────────────────────────────────────────────────────────────┤
│ NEW: Top Failing Positions    │ NEW: Geographic Distribution│
│ (table: cargo, errors, rate)   │ (horizontal bar by country)│
├─────────────────────────────────────────────────────────────┤
│ NEW: Recent High-Conf PEPs     │ NEW: Latest Corrections    │
│ (compact list)                 │ (compact list)            │
└─────────────────────────────────────────────────────────────┘
```

**Pros**: Quick to implement, stays on same page, lower risk
**Cons**: Dashboard gets crowded, mixes operational overview with analytics

### Variant B: Balanced (tabbed)

**Approach**: New `DashboardEstadisticas` component at `/dashboard/estadisticas` with tabs.

```
Overview | Precision | Geographic | Trends
┌─────────────────────────────────────────────────────────────┐
│ Filter Bar (date range, country)                           │
├─────────────────────────────────────────────────────────────┤
│ [KPI Row: Total PEPs | Total OPIs | Accuracy | Unread]       │
├─────────────────────────────────────────────────────────────┤
│ Main Chart Area (changes per tab)                           │
│ Tab 0: Volume trends (line chart)                            │
│ Tab 1: Precision matrix (bar chart by cargo/confidence)     │
│ Tab 2: Geographic map + country table                       │
│ Tab 3: Trend indicators + comparison tables                │
└─────────────────────────────────────────────────────────────┘
```

**Pros**: Clean separation, each tab focused, scalable
**Cons**: New route, more components, user has two dashboard URLs

### Variant C: Rich single-page (RECOMMENDED)

**Approach**: Enhance the existing `Dashboard.php` with a collapsible statistics section. Toggle button "Ver estadísticas" shows/hides the full analytics panel. All on one page, no new route.

```
┌─────────────────────────────────────────────────────────────┐
│ [Toggle: "Estadísticas"]                                    │
├─────────────────────────────────────────────────────────────┤
│ [KPI Cards (existing)]                                      │
│ [Script Status Panels (existing)]                           │
│ [Recent Activity Lists (existing)]                         │
├─ NEW STATISTICS (collapsible) ─────────────────────────────┤
│ Filter Bar (date range, country)                            │
│                                                             │
│ [Precision Widget]  [Volume Chart]  [Trend Widget]           │
│                                                             │
│ [Top Failing Table]  [Geographic Chart]  [Recent PEPs]       │
└─────────────────────────────────────────────────────────────┘
```

**Pros**: Single URL, existing dashboard becomes the analytics hub, progressive disclosure (not overwhelming initially), clean
**Cons**: Dashboard becomes more complex (but acceptable for admin/supervisor roles)

---

## Performance Strategy

### Query Complexity

- Volume by month: `GROUP BY DATE_TRUNC` on `resultados_scraping` — with 5k records, <50ms
- Precision by cargo: JOIN feedback + GROUP BY — with 500 feedbacks, <100ms
- Geographic: JOIN feedback + GROUP BY pais — similar

**Key consideration**: `clasificaciones_feedback` is new and small (likely <1k rows). `resultados_scraping` is the bigger table (unknown size, but based on recent project activity likely a few thousand rows).

### Caching Strategy

Use Laravel cache with moderate TTL (5-10 minutes):

```php
// In DashboardMetricsService
$key = "dashboard:metrics:{$pais}:{$dateRange}";

return Cache::remember($key, 300, function () use ($pais, $dateRange) {
    return $this->computeMetrics($pais, $dateRange);
});
```

Cache invalidation: Not automatic (TTL-based). Acceptable since metrics don't need real-time accuracy.

### Indexes to Verify

- `resultados_scraping.pais` — used in GROUP BY (needs index, check migration)
- `resultados_scraping.gemini_analyzed` — already indexed
- `resultados_scraping.gemini_categoria` — already indexed
- `resultados_scraping.fecha_encontrado` — likely already present
- `clasificaciones_feedback.tipo` — already indexed
- `clasificaciones_feedback.resultado_scraping_id` — foreign key, indexed

### Bottlenecks

- If `resultados_scraping` has >50k rows, monthly aggregates could hit 500ms+
- Solution: add partial index for `WHERE gemini_analyzed = true`
- Current table likely small enough that this isn't needed yet

---

## Approaches Comparison

### Approach 1: Single Component (Dashboard.php enhanced)

Add all logic to existing `Dashboard.php` component.

**Pros**: Minimal new files, reuses existing component, fast to implement
**Cons**: Component grows large, mixing existing operational metrics with new analytics

**Complexity**: Low
**Files touched**: 1 (Dashboard.php), 1 (dashboard.blade.php)

### Approach 2: Dedicated Service + Single Component (RECOMMENDED)

Create `DashboardMetricsService` that computes all metrics via raw queries with caching. Dashboard.php calls the service. Blade iterates over computed widget data.

**Pros**: Clean separation of concerns, service is testable, blade stays readable, easy to add more metrics
**Cons**: New service file, more abstraction

**Complexity**: Medium
**Files touched**: `Dashboard.php` (updated), `dashboard.blade.php` (updated), new `DashboardMetricsService.php`, new DTO files

### Approach 3: Parent + Child Widget Components

`Dashboard` (parent) renders `MetricCard`, `VolumeChart`, `PrecisionTable` etc. as child components. Each child loads its own data via computed properties.

**Pros**: Each widget is isolated, can lazy-load, fine-grained caching
**Cons**: More files, harder to share data between widgets, more complexity for what is essentially display logic

**Complexity**: High
**Files touched**: Many new components

---

## Recommendation

**Approach 2** — Dedicated Service + Enhanced Single Component.

**Rationale**:
- The dashboard is fundamentally a display component; the complexity is in the queries, not the UI structure
- A service keeps the component thin and testable
- Single component keeps it simple and avoids over-engineering
- This closes the loop: scraper → Gemini → feedback → metrics (the full cycle)

**UI**: Variant C — rich single-page with collapsible statistics section, extending the existing dashboard.

**Permission**: `ver dashboard estadisticas` for admin + supervisor. Operador sees the existing operational dashboard but not the statistics section.

**Chart approach**: Chart.js loaded from CDN (easiest integration), rendered via Alpine.js `x-data` + `wire:ignore` containers. No build step needed.

---

## Risks

1. **Performance under load**: If `resultados_scraping` grows to 50k+ rows, cached queries still need to run. Mitigation: partial indexes on `gemini_analyzed = true`.

2. **Data freshness**: Cached metrics (5-10 min TTL) may show stale data. Not critical for a statistics dashboard; acceptable.

3. **Permission model creep**: Adding new permissions may break if the seeder is re-run and doesn't account for existing users. Mitigation: use `firstOrCreate` for permissions.

4. **Chart.js + Livewire re-renders**: The `wire:ignore` pattern is necessary to prevent Livewire from wiping chart canvases. Must be disciplined about marking chart containers.

5. **No existing chart library**: Adding Chart.js CDN adds external dependency. Acceptable risk.

---

## Scope Boundaries

### IN (v1)
- Permission `ver dashboard estadisticas` (admin + supervisor)
- DashboardMetricsService with all computable metrics
- Filter bar (date range, country, category)
- KPI cards: total PEPs, total OPIs, accuracy %, unread ratio
- System precision widget: accuracy by confidence bucket, overall accuracy
- Volume trend chart (line chart, monthly PEP/OPI volume over 12 months)
- Top failing positions table (cargo, error count, error rate, min sample 3)
- Geographic distribution table (country, PEPs, OPIs, avg confianza)
- Recent high-confidence PEPs list (confianza >= 90, last 10)
- Trend indicators (this month vs last month for PEPs and OPIs)
- Toggle to show/hide statistics section on existing dashboard

### OUT (separate future changes)
- CSV/PDF export functionality
- Automated alerts (threshold-based notifications)
- Granular user-level precision metrics
- Comparison between time periods (custom date range picker beyond preset periods)
- Real-time websocket updates (metrics are polling-based, like the rest of the app)
- Dashboard for operador role (different use case)

---

## Implementation Sketch

```
app/
├── Services/
│   └── DashboardMetricsService.php   ← NEW: raw SQL aggregations, caching
├── DTOs/
│   ├── PrecisionMetrics.php          ← NEW: accuracy, precision by bucket
│   ├── VolumeMetrics.php              ← NEW: PEPs, OPIs, trends
│   ├── GeographicMetrics.php          ← NEW: by country
│   └── RecentActivity.php             ← NEW: recent PEPs, recent corrections
└── Livewire/
    └── Dashboard.php                 ← MODIFIED: add stats section, filter props

database/seeders/
└── RolesPermisosSeeder.php           ← MODIFIED: add permission

resources/views/livewire/
└── dashboard.blade.php               ← MODIFIED: add stats section below existing
```

### Key Metrics SQL (raw queries in service)

All aggregations use raw DB queries for performance:

```php
// Volume by month (last 12 months)
$result = DB::select("
    SELECT DATE_TRUNC('month', fecha_encontrado) as mes,
           COUNT(*) FILTER (WHERE gemini_is_pep = true AND gemini_categoria = 'PEP') as peps,
           COUNT(*) FILTER (WHERE gemini_is_pep = true AND gemini_categoria = 'OPI') as opis
    FROM resultados_scraping
    WHERE gemini_analyzed = true
      AND fecha_encontrado >= NOW() - INTERVAL '12 months'
    GROUP BY DATE_TRUNC('month', fecha_encontrado)
    ORDER BY mes ASC
");

// Precision by confidence bucket
$result = DB::select("
    SELECT CASE
               WHEN rs.gemini_confianza BETWEEN 0 AND 50 THEN '0-50'
               WHEN rs.gemini_confianza BETWEEN 51 AND 80 THEN '51-80'
               ELSE '81-100'
           END as bucket,
           COUNT(*) as total,
           COUNT(*) FILTER (WHERE fb.tipo = 'correcto') as correctos,
           ROUND(COUNT(*) FILTER (WHERE fb.tipo = 'correcto')::numeric / NULLIF(COUNT(*), 0) * 100, 1) as accuracy
    FROM resultados_scraping rs
    JOIN clasificaciones_feedback fb ON rs.id = fb.resultado_scraping_id
    GROUP BY bucket
");
```