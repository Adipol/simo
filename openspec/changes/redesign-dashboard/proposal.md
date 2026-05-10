# Proposal: redesign-dashboard

**Date**: 2026-05-10
**Change**: `redesign-dashboard` — Dashboard v2 (Major refactor + feature additions)
**Phase**: Propose
**Covers**: PR1 (UI core) + PR2 (instrumentation). PR3 → separate SDD `source-health-tracking`.

---

## Intent

The current dashboard is a 547-line monolithic Blade view backed by a `render()` with 8 inline queries — no narrative structure, no triage priority, no pipeline visibility. Users need to quickly identify "what's urgent", "is the system healthy", and "what arrived recently" — three concerns blurred into one undifferentiated wall of stats.

Simultaneously, the codebase carries confirmed tech debt: missing `#[Url]` on 3 filter properties, inline queries violating the Service layer contract, and a view that can't be tested or maintained at current size.

---

## Scope

### In Scope

**PR1 — UI Core (zero schema changes)**
- Refactor `Dashboard::render()` — remove all 8 inline queries; route through new Services
- New `DashboardSummaryService` (Layer 1+3 data, TTL 60s)
- New `DashboardHealthService` (Layer 2 data, TTL 15s; quota shows N/A until PR2)
- New DTOs: `DashboardSummaryDTO`, `PipelineHealthDTO`, `BacklogAgeDTO`
- Add `#[Url]` to `filtroDateRange`, `filtroPais`, `filtroCategoria`
- Split `dashboard.blade.php` (547 lines) → orchestrator slim view + 4 Blade sub-components
- Hero card (Layer 1): most urgent unreviewed `cambio` via `score = riesgo_alto×3 + es_mae×2 + días_pendiente/3`
- Status strip (Layer 2): scraper status, pep_monitor status, queue depth, Gemini quota stub
- Sparklines on KPI counts (7-day daily buckets via `DATE_TRUNC` — zero schema change)
- SVG choropleth heatmap for LATAM countries (inline SVG, replaces plain geo table)
- CSS variables for brand colors (`--simo-primary`, `--simo-accent`, etc.) in `app.css` — dark mode foundation only; no `dark:` variants
- Layer 2 permissions: simplified badge (green/yellow/red) for all users; detailed numbers gated to `admin | supervisor`
- Test coverage ≥ 85% on the 2 new services

**PR2 — Pipeline Instrumentation (migrations + service updates)**
- Migration: `add_gemini_analyzed_at_to_resultados_scraping`
- Migration: `add_gemini_analyzed_at_to_cambios`
- Migration: `create_log_gemini_usage` (model, FK to user/job optional)
- Update `GeminiFiltroService` + `GeminiAnalisisService` to write `gemini_analyzed_at`
- Update `GeminiService::send()` + `sendMultimodal()` to insert `log_gemini_usage` row
- `DashboardHealthService` switches from N/A stubs to real P50/P95 + daily quota data

### Out of Scope

- `source-health-tracking` SDD (per-source run logs, Python changes) → separate change
- Dark mode activation (`dark:` variants) → deferred to design system change
- Laravel Reverb / WebSockets → keep `wire:poll.15s`
- Tailwind v4 migration → project confirmed on v3.1
- Scraper Python code changes (of any kind)
- `wire:poll` interval changes or nested poll components

---

## Capabilities

### New Capabilities
- `dashboard-summary-service`: Computes Layer 1 (hero card, triage KPIs) + Layer 3 (recent items, backlog aging) via `DashboardSummaryService` + DTOs. Cache TTL 60s.
- `dashboard-health-service`: Computes Layer 2 (scraper status, queue depth, Gemini quota/latency) via `DashboardHealthService` + `PipelineHealthDTO`. Cache TTL 15s.
- `dashboard-v2-ui`: Four narrative layers rendered via Blade sub-components under `resources/views/components/dashboard/`. Hero card, status strip, sparklines, SVG heatmap.
- `gemini-usage-logging`: Captures `usageMetadata` (token counts) from Gemini API responses into `log_gemini_usage` table per API call. Enables daily quota visibility. (PR2)
- `pipeline-latency-tracking`: Stores `gemini_analyzed_at` timestamps on `resultados_scraping` and `cambios` to enable P50/P95 latency computation. (PR2)

### Modified Capabilities
- None — existing `DashboardMetricsService` (Layer 4 deep stats) is unchanged at the spec level.

---

## Approach

1. **Services first**: Create `DashboardSummaryService` and `DashboardHealthService` with full unit tests before touching `Dashboard.php` or Blade (TDD).
2. **DTO layer**: Define all DTOs (`DashboardSummaryDTO`, `PipelineHealthDTO`, `BacklogAgeDTO`) with `static empty()` for PR1 stubs (quota = 0, latency = null).
3. **Livewire wiring**: Replace 8 inline queries in `render()` with `#[Computed]` properties backed by the new services. Add `#[Url]` to 3 filter properties.
4. **Blade decomposition**: Slim down `dashboard.blade.php` to orchestrator only (<100 lines), extract 4 sub-components in `resources/views/components/dashboard/`.
5. **Frontend polish**: Sparklines (Alpine + Chart.js inline), SVG LATAM choropleth, CSS brand variables.
6. **PR2**: Additive — migrations + service updates. `DashboardHealthService` stubs switch to real data with no interface change.

---

## Affected Areas

| Area | PR | Impact | Description |
|------|-----|--------|-------------|
| `app/Livewire/Dashboard.php` | 1 | Modified | Remove 8 inline queries; add `#[Computed]`, `#[Url]` |
| `app/Services/Dashboard/DashboardSummaryService.php` | 1 | **New** | Layer 1+3 data, TTL 60s |
| `app/Services/Dashboard/DashboardHealthService.php` | 1 | **New** | Layer 2 data, TTL 15s |
| `app/Services/Dashboard/DTOs/DashboardSummaryDTO.php` | 1 | **New** | Hero card + KPI data |
| `app/Services/Dashboard/DTOs/PipelineHealthDTO.php` | 1 | **New** | Health strip data |
| `app/Services/Dashboard/DTOs/BacklogAgeDTO.php` | 1 | **New** | Aging buckets |
| `resources/views/livewire/dashboard.blade.php` | 1 | Modified | Slim orchestrator (~100 lines) |
| `resources/views/components/dashboard/` | 1 | **New dir** | 4 sub-components (action, health, discovery, analytics) |
| `resources/css/app.css` | 1 | Modified | CSS brand variables, sparkline utilities |
| `app/Services/Gemini/GeminiService.php` | 2 | Modified | Capture `usageMetadata`, insert `log_gemini_usage` |
| `app/Services/Gemini/GeminiFiltroService.php` | 2 | Modified | Set `gemini_analyzed_at` on persist |
| `app/Services/Gemini/GeminiAnalisisService.php` | 2 | Modified | Set `gemini_analyzed_at` on persist |
| `database/migrations/*` | 2 | **New** | 3 migrations: `gemini_analyzed_at` ×2, `log_gemini_usage` |
| `tests/Feature/Services/Dashboard/` | 1+2 | **New** | Service unit tests (≥85% coverage target) |

---

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| `wire:poll.15s` × 2 new services = query regression | Low | Estimated 10–15 cheap indexed queries per cycle (vs 8 now). Add `(revisado, fecha)` composite index before deploy. Benchmark with `EXPLAIN ANALYZE`. |
| Hero card formula feels wrong to users | Low | Formula validated by user in explore phase. Add feature flag (`config('dashboard.hero_formula')`) if tweaking is needed post-deploy. |
| PR2 `gemini_analyzed_at` has no historical data | Certain | Widgets display "N/A" with tooltip "Datos disponibles desde [deploy date]". Not a bug — communicate to users. |
| Blade sub-component prop drilling complexity | Low | Keep sub-components purely presentational (no Livewire, no DB calls). Pass only DTOs. |
| SVG choropleth LATAM coverage gaps | Medium | Countries not in DB show as grey (no data). Acceptable UX. Ensure `GeographicMetricsDTO` country codes match SVG path IDs. |

---

## Rollback Plan

**PR1**: Pure UI + service addition. No schema changes. Rollback = revert PR1 branch. No data migration concerns.

**PR2**: Three additive migrations. Rollback = run `php artisan migrate:rollback` (no destructive `DROP` on existing columns — new tables/columns only). Revert `GeminiService` changes. The dashboard reverts to N/A stubs for quota/latency gracefully.

---

## Dependencies

- `DashboardMetricsService` must remain stable during PR1 (no changes to existing service)
- `GeminiService` response structure must include `usageMetadata` in API response (confirmed by Gemini 2.5-flash API docs — already returns it, SIMO just discards it)
- Gemini API must continue returning structured responses (no contract break risk)

---

## Success Criteria

- [ ] Zero direct Eloquent queries inside `Dashboard::render()` (verified by code review + grep)
- [ ] Test coverage ≥ 85% on `DashboardSummaryService` and `DashboardHealthService` (measured by `php artisan test --coverage`)
- [ ] `wire:poll.15s` cycle triggers ≤ 15 total DB queries (measured via `DB::listen` in a test env)
- [ ] Dashboard renders correctly at ≥ 768px viewport (manual QA + browser resize)
- [ ] Layer 2 detailed metrics (queue depth, quota, latency) not visible to users without `admin` or `supervisor` role (Livewire test with `actingAs(regularUser)`)
- [ ] Hero card displays the correct highest-score unreviewed `cambio` (Service unit test with controlled seed data)
- [ ] All 3 filter properties (`filtroDateRange`, `filtroPais`, `filtroCategoria`) persist in URL via `#[Url]` (Livewire test + manual browser QA)
- [ ] Pre-commit hook passes clean (`php artisan pint --test` + PHPStan) — no `--no-verify` bypass
- [ ] PR2: `log_gemini_usage` row inserted for every Gemini API call (feature test with real DB + mock HTTP)
- [ ] PR2: `gemini_analyzed_at` populated on both tables after Gemini processing (feature test)
