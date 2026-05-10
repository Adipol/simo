# Exploration: redesign-dashboard

**Date**: 2026-05-10  
**Change**: `redesign-dashboard` — Dashboard v2 (Major refactor + feature additions)  
**Phase**: Explore  

---

## Current State

### Stack (verified)

| Artifact | Version |
|---|---|
| Laravel | ^12.0 |
| Livewire | ^4.2 |
| PHP | ^8.2 |
| PostgreSQL | 17 |
| Tailwind CSS | **^3.1** (package.json — NOT v4, despite `@tailwindcss/vite ^4.0.0` being installed as devDep) |
| Alpine.js | ^3.4.2 |
| Chart.js | 4.4.1 (CDN in blade) |
| Spatie Permissions | ^7.2 |
| Laravel Horizon | **NOT installed** — queue driver is `database` (jobs table), no horizon.php config |

> **CRITICAL**: Tailwind 3 confirmed. The project-standards registry mentions Tailwind 4, but `tailwindcss: ^3.1.0` is in package.json. Do NOT use Tailwind v4 CSS variable syntax.

---

## Section A — Codebase Reality Check

### A1. Dashboard Architecture Today

**`app/Livewire/Dashboard.php`** (150 lines):
- `render()` has **8 inline Eloquent/query calls** — confirmed tech debt (obs #818)
- Filter properties `filtroDateRange`, `filtroPais`, `filtroCategoria` exist but **lack `#[Url]`** — confirmed
- Stats section uses `#[Computed]` correctly with lazy-loading guard (`if (!$mostrarEstadisticas) return DTO::empty()`)
- `buildFilters()` helper exists — clean pattern
- Uses `DashboardMetricsService` for all stats section data — well-architected

**`resources/views/livewire/dashboard.blade.php`** (547 lines):
- `wire:poll.15s` on root `<div>` — polls all data every 15 seconds
- Chart.js 4.4.1 loaded from CDN on every dashboard load
- `wire:ignore` used correctly on chart canvas
- No Blade sub-components — monolithic view
- All CSS via Tailwind utilities + `simo-*` classes

### A2. Existing DTOs — Inventory and Reuse Assessment

| DTO | Properties | Reusable for v2? |
|---|---|---|
| `VolumeMetricsDTO` | `totalPeps`, `totalOpis`, `analyzedCount`, `unreadCount`, `monthlyTrend[]`, `hasData` | ✅ Extend — add `sparklineData[]` property |
| `PrecisionMetricsDTO` | `overallAccuracy`, `byBucket[]`, `totalFeedbacks`, `hasData` | ✅ Keep as-is for Layer 4 |
| `TrendIndicatorsDTO` | `pepsTrend[]`, `opisTrend[]`, `feedbackTrend[]` | ✅ Keep — but add sparkline arrays |
| `RecentActivityDTO` | `highConfidencePeps[]`, `latestCorrections[]` | 🔄 Extend — add `urgentChange` (Layer 1 hero) |
| `GeographicMetricsDTO` | `byCountry[]`, `hasData` | ✅ Keep — add for heatmap |

**New DTOs needed**:
- `DashboardSummaryDTO` — Layer 1+3 fast data (top section): KPI counts, hero card, recent items
- `PipelineHealthDTO` — Layer 2: scraper status, pep_monitor status, queue depth, latency, source health
- `BacklogAgeDTO` — aging buckets (>1d, >3d, >7d)
- `SourceHealthDTO` — per-source last run, errors (if schema added)

### A3. Database Fields for the 5 New Metrics

#### Metric 1: Backlog Aging (pendientes > 3 días)
- **Status: ✅ FEASIBLE with existing data**
- `cambios.fecha` + `cambios.revisado = false` → `WHERE fecha < NOW() - INTERVAL '3 days'`
- `cambios.gemini_analyzed` + `cambios.revisado = false` → for "unanalyzed backlog"
- No migration needed. Can add `cambios_backlog_aging_idx` for perf: `(fecha) WHERE revisado = false`

#### Metric 2: Cola Gemini + cuota diaria
- **Queue depth: ✅ FEASIBLE** — `SELECT COUNT(*) FROM jobs WHERE queue = 'gemini'` (standard Laravel jobs table)
- **Token/quota tracking: ❌ NOT AVAILABLE** — `GeminiService::send()` does NOT capture `usageMetadata` from the API response. The Gemini REST API returns `usageMetadata.totalTokenCount` in every response but SIMO discards it entirely.
- **Path to fix**: Add `gemini_token_usage` table OR add columns to an existing log. Minimum: capture `input_tokens + output_tokens` per API call with a `created_at` timestamp so we can SUM daily.
- **Requires**: New `log_gemini_usage` table migration + update `GeminiService::send()` and `sendMultimodal()` to insert a log row after each call.

#### Metric 3: Salud por fuente
- **Status: ⚠️ PARTIAL** — `log_scripts` tracks `scraper` and `pep_monitor` globally, but NOT per individual `fuente`.
- `fuentes.ultimo_check` exists — shows when a fuente was last checked by the scraper
- `log_scripts.errores` is a global count per script run, not per fuente
- **For full per-source health**: Need new `log_fuente_runs` table (fuente_id, inicio, fin, estado, error) OR enrich `log_scripts` with a JSON field for per-fuente breakdown.
- **Minimum viable**: Use `fuentes.ultimo_check` + `cambios` last `fecha` per fuente to approximate "last activity" without a new table.

#### Metric 4: Latencia del pipeline (P50/P95)
- **Status: ❌ NOT COMPUTABLE with current schema**
- `resultados_scraping.fecha_encontrado` = when scraper detected the article (written by Python)
- There is **no `gemini_analyzed_at` timestamp** anywhere in `resultados_scraping` or `cambios`
- The only way to compute latency is to add a `gemini_analyzed_at TIMESTAMP` column to both tables, populated when `GeminiFiltroService::persistirResultado()` and `GeminiAnalisisService` write their results.
- **Requires**: 2 migrations (`add_gemini_analyzed_at_to_resultados_scraping`, `add_gemini_analyzed_at_to_cambios`) + updates to both services.
- **Data bootstrap**: Historical rows will have NULL — P50/P95 only become meaningful after migration deploys.

#### Metric 5: Última actividad humana
- **Status: ⚠️ PARTIAL** — `clasificaciones_feedback.created_at` timestamps exist → "last feedback" is available
- There is **no `revisado_at`** on `cambios` — `marcarComoRevisado()` only sets `revisado = true`, no timestamp
- For "last cambio review": Need `cambios.revisado_at TIMESTAMP NULLABLE` + update `Cambio::marcarComoRevisado()` to also set the timestamp.
- **Minimum viable**: Use `clasificaciones_feedback.created_at MAX()` as a proxy for "last human activity" — available NOW without schema changes.

---

## Section B — Architectural Options

### B1. Service Strategy

**Option A: Extend `DashboardMetricsService`**
- Pros: One service, no new file, existing cache/filter machinery reusable
- Cons: Service already has 538 lines — grows to ~900+. Violates Single Responsibility. Hard to test individual concerns. Slower cache invalidation (one key space for all).
- Effort: Low initially, increases maintenance cost over time

**Option B: Split into focused services** ← RECOMMENDED
- `DashboardSummaryService` — fast KPIs + triage data (Layer 1+3): `resultados`, `cambios`, recent items
- `DashboardHealthService` — pipeline health (Layer 2): scraper status, queue depth, Gemini status
- Keep `DashboardMetricsService` — deep stats (Layer 4)
- Pros: SRP, independent cache TTLs, independent testing, clear ownership
- Cons: 2 new files, small DI overhead
- Effort: Medium

**Option C: Single `DashboardSnapshotService` with all data**
- Pros: One call, one DTO, trivial to pass to view
- Cons: Monolith, forces same TTL on everything, hard to test, all-or-nothing failure
- Effort: Low, poor long-term

**Recommendation**: **Option B**. The different data layers have fundamentally different freshness requirements — KPIs need <30s, deep stats can be 5 min. Splitting by concern is the right move.

---

### B2. Caching Strategy

Current: All `DashboardMetricsService` methods → 5min TTL (300s) flat.

| Layer | Data | Recommended TTL |
|---|---|---|
| Layer 1 hero card | Most urgent cambio | 30s |
| Layer 1 KPI strip | Pending counts | 60s |
| Layer 2 pipeline health | Scraper status, queue depth | 15s (matches poll) |
| Layer 2 Gemini quota | Token usage | 60s |
| Layer 3 recent items | Latest resultados/cambios | 60s |
| Layer 4 deep stats | Precision, geo, trends | 300s (current, keep) |

**Approach**: Per-method TTL via named cache keys. All existing stats caching stays at 300s. New services introduce their own TTL constants.

**Note on invalidation**: Since we use `wire:poll.15s`, the cache just needs to be ≤ poll interval for live-feeling data. There's no need for cache invalidation on write unless we add it explicitly (overkill for now).

---

### B3. DTO Granularity

**Option A: One big `DashboardSnapshotDTO`**
- Pros: Single pass, easy to serialize
- Cons: Forces computing all data together, can't lazy-load per layer, test isolation poor

**Option B: Multiple small DTOs, one per layer** ← RECOMMENDED
- `DashboardSummaryDTO` (Layers 1+3): counts, hero, recent
- `PipelineHealthDTO` (Layer 2): scraper/pep health, queue, quota
- Keep existing DTOs for Layer 4
- Pros: Lazy-loading in Livewire via `#[Computed]`, independent caching, unit-testable
- Cons: More files

**Recommendation**: **Option B**. Lazy loading with `#[Computed]` guards per layer is essential for perf.

---

### B4. Sparklines Data

Sparklines need hourly buckets for the last 24h (or daily for the last 7 days).

**Option A: Query on-the-fly with `GROUP BY hour`**
- At current data volume (small startup), fully viable
- `SELECT DATE_TRUNC('hour', fecha_encontrado), COUNT(*) FROM resultados_scraping WHERE fecha_encontrado > NOW()-INTERVAL '24h' GROUP BY 1`
- Cost: ~1-2ms with existing `fecha_encontrado` index
- Cache at 5min → effectively free on `wire:poll.15s`
- Pros: Zero schema changes, no maintenance
- Cons: Slightly more load at scale (thousands of rows/hour)

**Option B: Pre-computed hourly aggregate table**
- Pros: Sub-millisecond, arbitrarily scalable
- Cons: New table, backfill migration, Observer/Job to maintain it, significant overhead for current data volumes
- Effort: High

**Recommendation**: **Option A** (on-the-fly). At SIMO's current scale (scraper runs hourly, ~24 fuentes), Option A is entirely sufficient. Revisit at scale.

---

### B5. Real-time Updates

**Option A: Keep `wire:poll.15s`** ← RECOMMENDED (short-term)
- Pros: Zero infra changes, works with database queue, battle-tested
- Cons: 15s refresh may feel stale for urgent pipeline events; HTTP request every 15s per open tab

**Option B: Livewire Echo + Reverb (WebSocket push)**
- Pros: True real-time, event-driven
- Cons: Requires Reverb (new infra), `laravel/reverb` package, broadcast events from scraper heartbeat and Gemini jobs — significant complexity
- Effort: High

**Option C: Split polling** — fast poll (15s) for health/KPIs via a lightweight endpoint, slow poll (60s or manual) for deep stats
- Pros: Reduces server load, better UX hierarchy
- Cons: Requires refactoring the component's poll strategy (multiple `wire:poll` with different intervals, or nested components)
- Effort: Medium

**Recommendation**: **Option A** for now (keep `wire:poll.15s`). If the user wants "true real-time" for scraper health, revisit Option B as a separate change. Splitting poll intervals (Option C) is a nice optimization but adds complexity.

---

### B6. Frontend Approach — Blade Structure

**Option A: Monolithic dashboard.blade.php (extend current)**
- Current view is 547 lines. Adding 4 layers would push it to 1000+ lines.
- Pros: No refactor of component inclusion chain
- Cons: Unmaintainable, hard to test, violates project component standards

**Option B: Extract Blade sub-components** ← RECOMMENDED
- `x-dashboard.action-layer` (Layer 1)
- `x-dashboard.health-strip` (Layer 2)
- `x-dashboard.discovery-layer` (Layer 3)
- `x-dashboard.analytics-section` (Layer 4, keep current stats)
- Each in `resources/views/components/dashboard/`
- Pros: Maintainable, testable, reusable, aligns with project standards
- Cons: More files, needs `wire:key` in loops

**Recommendation**: **Option B**. At 547 lines already, this is necessary regardless of v2 features.

---

## Section C — Risks and Unknowns

### C1. Metrics Requiring Schema Changes

| Metric | Migration needed | Risk |
|---|---|---|
| **Pipeline latency (P50/P95)** | `add_gemini_analyzed_at` to `resultados_scraping` + `cambios` | Medium — only future rows have data; no historical P50/P95 |
| **Token quota tracking** | New `log_gemini_usage` table + `GeminiService` changes | Low — additive, no existing behavior broken |
| **Per-source health** | New `log_fuente_runs` table OR `log_scripts` enrichment | Medium — Python scraper must write new rows |
| **Última revisión de cambios** | `cambios.revisado_at` + update `marcarComoRevisado()` | Low — additive |
| **Backlog aging** | None (can add partial index only) | Zero |

### C2. Performance Impact of `wire:poll.15s`

Current render: 8 queries (all simple COUNT/LIMIT). Layer 1 target: ~5 fast queries (COUNT, MAX, recent 5).
Layer 2 health: 3 queries (log_scripts ×2 + jobs COUNT). Layer 3 recent: 2 queries.

**Estimated total queries per poll cycle (v2)**: 10-15 cheap queries (indexed PKs, COUNT with partial indexes).

The Layer 4 deep stats are already gated behind `$mostrarEstadisticas = false` and cached at 5min — they are NOT polled 15s. This design is CORRECT and should be preserved.

**Risk**: LOW at current data volume. Add composite index on `(revisado, fecha)` for cambios backlog query.

### C3. Permissions

Current: Stats section gated by `@can('ver dashboard estadisticas')`.

Layer analysis:
- **Layer 1 (Acción)**: Should be visible to ALL authenticated users — it's the triage strip
- **Layer 2 (Salud)**: Pipeline health (scraper up/down, queue depth) → arguably all users need to know "is the system working?" Consider showing simplified health (green/red indicator) to all, detailed metrics to admin/supervisor only
- **Layer 3 (Descubrimiento)**: Recent items → same as current unreviewed items, visible to all
- **Layer 4 (Análisis profundo)**: Keep existing `ver dashboard estadisticas` gate

**Decision needed**: Should Layer 2 details (queue depth, token quota, latency) be gated? Recommendation: keep a simplified health badge for all, full Layer 2 for admin/supervisor.

### C4. Mobile Behavior

Current dashboard: `grid-cols-2 lg:grid-cols-4` on KPIs — collapses to 2-column on mobile. Acceptable but dense.

4 narrative layers on mobile concern:
- Layer 1 hero card + triage strip: Works as single column, hero card takes full width ✅
- Layer 2 health strip: Horizontal strip becomes vertical list on mobile — manageable
- Layer 3 recent items: Current 2-col grid → 1-col on mobile ✅
- Layer 4: Already collapsible, no mobile issue

**Design implication**: Use progressive disclosure — Layer 1 is always visible, Layer 2 can be a collapsible "System Status" card (collapsed by default on mobile), Layer 3 paginated/limited on mobile.

### C5. Testing Strategy

Given strict TDD:
- **Services (DashboardSummaryService, DashboardHealthService)**: Unit tests with DB factories — test each compute method independently
- **DTOs**: Pure value object tests (no DB needed)
- **Livewire component**: `Livewire::test(Dashboard::class)` — test that computed properties return correct DTO types; test `toggleEstadisticas` authorization
- **Sparklines**: Test the SERVICE method that produces the hourly array — assert shape `[['hour'=>..., 'count'=>...]]`
- **Chart.js visual elements**: NOT tested in PHP. The Blade renders the Alpine `x-data` attribute — we test the data shape in service tests, not the Chart.js render
- **Queue depth metric**: Test that `DashboardHealthService::getQueueDepth()` queries the `jobs` table correctly — use `Queue::fake()` + assert DB row count
- **NO browser/e2e tests** in scope (e2e is false in openspec/config.yaml)

---

## Section D — Scope Boundaries

### D1. The 5 New Metrics — Feasibility Matrix

| Metric | Existing data? | Migration needed? | Effort |
|---|---|---|---|
| **Backlog aging** | ✅ Yes | Optional index only | Low |
| **Cola Gemini (depth)** | ✅ Yes (`jobs` table) | None | Low |
| **Cuota diaria Gemini** | ❌ No | `log_gemini_usage` table + GeminiService changes | Medium |
| **Salud por fuente** | ⚠️ Partial (`fuentes.ultimo_check`) | `log_fuente_runs` requires Python changes | High |
| **Latencia pipeline P50/P95** | ❌ No | `gemini_analyzed_at` on 2 tables | Medium-High |
| **Última actividad humana** | ⚠️ Partial (feedback.created_at) | `cambios.revisado_at` optional | Low |

**Recommendation for PR scope**:
- **PR 1 (Core v2 UI + existing data)**: Layers 1-4 with backlog aging, queue depth, partial última actividad — zero schema changes
- **PR 2 (Instrumentation)**: `log_gemini_usage` + `gemini_analyzed_at` columns + GeminiService updates → enables latency and quota
- **PR 3 (Source health)**: `log_fuente_runs` table + Python scraper updates → per-source health strip (requires coordination with Python)

### D2. Geographic Heatmap

| Option | Fits stack? | Effort |
|---|---|---|
| **Chart.js Geo plugin** (choropleth) | Fits (Chart.js already loaded) | Medium — new plugin CDN, requires GeoJSON |
| **SVG inline map** (hardcoded LATAM countries) | Fits perfectly | Medium — but LATAM-only, no generic solution |
| **Leaflet.js** | Does NOT fit — separate lib, 140KB | High |
| **Plain table (current)** | Already working | Zero |

**Recommendation**: Keep the plain table for PR 1. The data is already structured correctly. A choropleth heatmap adds significant complexity (GeoJSON data, plugin loading) for questionable value — LATAM countries are already named. Re-evaluate if user insists.

### D3. Dark Mode

Dark mode from day one requires:
- Every color class to have a `dark:` variant
- CSS variables for theme colors (possible in Tailwind 3 via `@layer`)
- Additional Blade complexity

**Assessment**: **Medium-High lift** if designed in from day one. Current codebase has ZERO `dark:` classes anywhere. Adding dark mode support doubles CSS class declarations. 

**Recommendation**: Design with dark-mode-ready CSS variable approach in mind (use CSS variables for brand colors), but do NOT implement `dark:` variants in this change. Mark as "deferred to design system" change.

---

## Architectural Recommendation (Summary)

```
Dashboard v2 Architecture:
├── Layer 1+3 → DashboardSummaryService (new) → DashboardSummaryDTO (new)
│    ├── KPI counts (resultados hoy, sin leer, cambios, backlog aging)
│    ├── Hero card (most urgent unreviewed cambio with risk)
│    └── Recent items (últimos resultados + cambios con confidence bars)
│
├── Layer 2 → DashboardHealthService (new) → PipelineHealthDTO (new)
│    ├── Scraper/pep_monitor status (from log_scripts — existing)
│    ├── Queue depth (from jobs table — existing)
│    └── Gemini quota (stub/0 until PR 2 adds log_gemini_usage)
│
└── Layer 4 → DashboardMetricsService (existing, unchanged)
     └── All current stats — precision, geo, trends, volume

Livewire Dashboard.php:
├── #[Computed] summaryData(): DashboardSummaryDTO  (TTL 60s)
├── #[Computed] healthData(): PipelineHealthDTO       (TTL 15s)
├── (existing #[Computed] for stats section — keep as-is)
└── #[Url] on filtroDateRange, filtroPais, filtroCategoria (fix debt)

Blade:
├── dashboard.blade.php (orchestrator — slim, <100 lines)
├── components/dashboard/action-layer.blade.php    (Layer 1)
├── components/dashboard/health-strip.blade.php    (Layer 2)
├── components/dashboard/discovery-layer.blade.php  (Layer 3)
└── components/dashboard/analytics-section.blade.php (Layer 4)
```

---

## Affected Areas

- `app/Livewire/Dashboard.php` — refactor render(), add `#[Url]`, add new `#[Computed]`
- `app/Services/Dashboard/DashboardMetricsService.php` — no changes (keep)
- `app/Services/Dashboard/DashboardSummaryService.php` — **NEW**
- `app/Services/Dashboard/DashboardHealthService.php` — **NEW**
- `app/Services/Dashboard/DTOs/DashboardSummaryDTO.php` — **NEW**
- `app/Services/Dashboard/DTOs/PipelineHealthDTO.php` — **NEW**
- `resources/views/livewire/dashboard.blade.php` — full redesign
- `resources/views/components/dashboard/` — **NEW** directory with 4 components
- Migrations (PR 2): `add_gemini_analyzed_at`, `create_log_gemini_usage`
- `app/Services/Gemini/GeminiService.php` (PR 2): capture `usageMetadata`

---

## Open Questions for User

1. **Layer 2 permissions**: Should queue depth / Gemini quota be visible to ALL users or only admin/supervisor? Current proposal: simplified health badge (green/yellow/red) for all, detailed numbers gated.

2. **Hero card (Layer 1)**: The "most urgent unreviewed change" needs a priority formula. Proposed: `score = (riesgo=alto ? 3 : riesgo=medio ? 2 : 1) + (es_mae ? 2 : 0) + (days_pending / 3)`. Is this correct? Are there other urgency signals?

3. **Gemini quota metric**: The API doesn't enforce a hard daily limit from SIMO's side — it's Google's rate limiter. Do you want to track token usage for cost monitoring purposes, or just show "queue depth"? If just queue depth, PR 2 can be deferred.

4. **Per-source health**: Requires Python scraper to write `log_fuente_runs` rows. Is that in scope for this change, or defer to a separate `source-health-tracking` change?

5. **Pipeline latency**: Do you want `gemini_analyzed_at` added now (schema migration, data starts accumulating), even if the P50/P95 metric shows "N/A" until enough data exists?

6. **Geographic heatmap**: Keep plain table (fast, working, zero effort) or invest in SVG choropleth for LATAM? This is primarily cosmetic.

7. **Dark mode**: Defer completely, or add CSS variables for brand colors now as a foundation?

8. **Chained PRs split**: Does the proposed PR1/PR2/PR3 split match your expectations, or do you want to include instrumentation (PR 2) in the initial push?

---

## Ready for Proposal

**Yes** — with user answers to questions #1, #2, and #8 (permissions, hero formula, PR scope). Questions #3-7 can default to the recommendations above if the user has no preference.
