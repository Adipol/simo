# Proposal: feedback-loop-from-descartados

**Change**: `feedback-loop-from-descartados` · **Phase**: propose · **Date**: 2026-05-14

## Why

Production carries **153 descartados sitting unused** (89% of 171 rows at 2026-05-13), each with full Gemini metadata (`gemini_confianza`, `gemini_motivo`, `gemini_categoria`) — pure negative-label signal that nothing reads today. The operator's product-owner insight from engram #896: those discards are *labeled training data*, not noise. Initial precision baseline is **10/(10+153) = 6.1%** and 5 keywords already show ≥82% discard rate (`renuncia`, `posesión`, `asume`, `renunciar`, `posesionado`). Infrastructure makes this cheap: Chart.js 4.4.1 already loaded (`dashboard.blade.php:43`), `DashboardMetricsService` sibling pattern established, zero new dependencies needed.

## What Changes

- **NEW migration**: btree index on `resultados_scraping.sitio_id` (currently missing — flagged in explore)
- **NEW `DescartadosAnalisisService`** (sibling of `DashboardMetricsService`, NOT an extension) with: precision metrics, per-keyword analysis, per-sitio analysis, drift calc (30d vs 60d), Gemini-confianza buckets, plus `getNegativeExamples(int $limit = 10): Collection` seam for future T3
- **NEW DTOs**: `DescartadosMetricsDTO`, `KeywordAnalisisDTO`, `SitioAnalisisDTO`, `DriftDTO` (each with `fromArray()` per AGENTS.md)
- **NEW artisan command** `simo:analizar-descartados` (T1) with flags `--dias=30`, `--categoria`, `--keyword`, `--min-sample=5`
- **NEW Livewire component** `PrecisionDashboard` at `/admin/precision` (T2) — full-page, `wire:poll.300s`
- **NEW Blade view** with 4 Chart.js charts (precision overview, keyword bars, sitio bars, drift)
- **NEW route** in `routes/web.php` protected by `gestionar resultados`
- **NEW tests**: feature tests for command, service, Livewire (TDD-first)

## Out of Scope

- **T3** (auto-feedback to Gemini prompt with negative examples) — future SDD; only the seam is exposed now
- Modifying scrapers (Python or Laravel)
- New columns on `resultados_scraping` (only one new index)
- Changing the descartar/archivar UX
- ML pipeline / auto-threshold tuning
- Notifications / alerts
- Purge of old descartados (separate technical debt)

## Capabilities

### New Capabilities
- `descartados-analisis`: Aggregation engine over `resultados_scraping` discarded rows. Defines precision metrics, per-keyword/per-sitio breakdowns, drift window, sample guards, and the `getNegativeExamples()` contract for downstream consumers.
- `precision-dashboard`: Admin-only `/admin/precision` Livewire view that consumes `descartados-analisis` and renders Chart.js visualizations with auto-poll refresh.

### Modified Capabilities
- None. Existing capabilities (`ci-pipeline`, `dedupe-safety-net`) are untouched.

## Approach

Standalone Livewire page + sibling service + Chart.js (no new deps). T1 (artisan command) is the data-extraction layer; T2 (dashboard) is the visualization layer. **Both consume the same `DescartadosAnalisisService` so they can never disagree on numbers.** Rationale captured in engram #921: descartados are an *implicit* negative signal vs `clasificaciones_feedback` (explicit) — must not be conflated, hence sibling-not-extension. Sample guards (N≥5 per keyword, N≥10 global) are mandatory at this volume to avoid misleading percentages; configurable via `--min-sample` flag.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/{date}_add_sitio_id_idx_to_resultados_scraping.php` | New | btree on `sitio_id` via `CREATE INDEX CONCURRENTLY` (online deploy) |
| `app/Services/DescartadosAnalisisService.php` | New | Core query engine, sibling of `DashboardMetricsService` |
| `app/Services/Dashboard/DTOs/DescartadosMetricsDTO.php` | New | Aggregate output container |
| `app/Services/Dashboard/DTOs/KeywordAnalisisDTO.php` | New | Per-keyword row |
| `app/Services/Dashboard/DTOs/SitioAnalisisDTO.php` | New | Per-sitio row |
| `app/Services/Dashboard/DTOs/DriftDTO.php` | New | 30d-vs-60d comparison row |
| `app/Console/Commands/AnalizarDescartados.php` | New | T1 CLI; mirrors `LimpiarLogs` pattern |
| `app/Livewire/Admin/PrecisionDashboard.php` | New | T2 full-page Livewire; `final class`, `#[Computed]` props |
| `resources/views/livewire/admin/precision-dashboard.blade.php` | New | 4 Chart.js charts + Alpine.js init |
| `routes/web.php` | Modified | Add `/admin/precision` route + `gestionar resultados` middleware |
| `tests/Feature/Commands/AnalizarDescartadosTest.php` | New | Command behavior + edge cases |
| `tests/Feature/Services/DescartadosAnalisisServiceTest.php` | New | Per-method unit coverage |
| `tests/Feature/Livewire/PrecisionDashboardTest.php` | New | Render + auth + state |

**NOT touched**: `Resultados.php`, `ResultadoScrapingQueryService.php`, Python scraper, `resultados_scraping` columns.

## Estimated Size

| Layer | LOC |
|---|---|
| Migration | ~30 |
| Service + 4 DTOs | ~120 |
| Command | ~50 |
| Livewire component | ~40 |
| Blade view | ~80 |
| Tests (TDD-heavy) | ~200 |
| **Total** | **~520 LOC** |

**Above the 400-line review budget.** `sdd-tasks` MUST forecast review workload and likely recommend chained PR slices (e.g., PR-A: migration + service + DTOs + service tests; PR-B: command + command tests; PR-C: Livewire + view + view tests). Per cached `chain_strategy: stacked-to-main`, slices target `main`. Final decision deferred to tasks phase per `delivery_strategy: ask-on-risk`.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Low Relevantes volume (10) makes initial precision misleading | High | Label UI as "baseline inicial — crece con uso del sistema"; show N alongside every % |
| Min-sample guards too strict on day 1 (hide all keywords) | Medium | Tunable via `--min-sample=N` CLI flag; default 5; UI exposes a slider in future iteration |
| Drift window empty if system <60d data | Medium | Render "N/D" gracefully; never crash on null prev-period |
| Index migration locks table during deploy | Low | `CREATE INDEX CONCURRENTLY` in pgsql — no table lock |
| Performance at scale (>10K descartados) | Low | Current btree indexes sufficient; document partial-index threshold in archive |
| Sibling service drifts from `DashboardMetricsService` cache pattern | Low | Mirror `Cache::remember()` TTL=300s to match `wire:poll.300s` |
| Confusion between `clasificaciones_feedback` precision vs descartados precision | Medium | Service name + UI labels make signal source explicit; documented in spec |

## Rollback Plan

- **Migration**: `php artisan migrate:rollback --step=1` drops the index (no data loss; index is purely a performance hint).
- **Service / DTOs / Command**: delete files; no schema or runtime dependency in production paths.
- **Route + Livewire**: revert the route line in `routes/web.php` and delete the component/view; the link disappears from navigation. No user-facing data is mutated.
- **Per chained-PR**: each slice is independently revertible because slices are cumulative-additive.

## Dependencies

- Chart.js 4.4.1 — already loaded via CDN in `dashboard.blade.php:43`. The standalone `/admin/precision` layout must include the same `<script>` tag (one line).
- Spatie Permissions — `gestionar resultados` already seeded in `RolesPermisosSeeder` for admin + supervisor.
- PostgreSQL 17 `CREATE INDEX CONCURRENTLY` (production) and SQLite-compatible fallback for tests (no `CONCURRENTLY` keyword — handled per driver in the migration).

## Success Criteria

- [ ] `php artisan simo:analizar-descartados` against prod data prints a sane keyword breakdown (top problematic keywords match `renuncia` / `posesión` / `asume` / `renunciar` / `posesionado` from session validation).
- [ ] `/admin/precision` renders 4 Chart.js charts for admin user; returns 403 for `operador`.
- [ ] Service unit tests cover each metric + edge cases (N<5, empty prev period, null `relevante`).
- [ ] Command feature test verifies output format + `--min-sample` flag behavior.
- [ ] Livewire feature test verifies render + auth gate + `wire:poll` directive.
- [ ] CI green on PR (or final PR of chain) — no regression in existing 860 tests.
- [ ] Migration runs cleanly on pgsql 17 production with zero downtime (CONCURRENTLY).

## Verification Plan

1. **Service**: per-method unit tests with seeded fixtures (RefreshDatabase) — precision, per-keyword guard, per-sitio guard, drift with/without prev period, confianza buckets, `getNegativeExamples()`.
2. **Command**: feature test asserts `expectsTable()` output for happy path, `expectsOutput()` for "datos insuficientes" path, and option parsing for `--dias` / `--categoria` / `--keyword` / `--min-sample`.
3. **Livewire**: `Livewire::actingAs($admin)->test(PrecisionDashboard::class)` for render + state; second test as `operador` asserting 403.
4. **Manual smoke** (post-merge): SSH into VPS, run `php artisan simo:analizar-descartados --dias=30` against prod, sanity-check the 5 known problematic keywords surface at the top.
5. **CI gate**: PR (or final chained PR) must be green.

## Open Questions

- None blocking. Cached decisions from session pause (engram #922) cover scope (T1+T2, T3 out), placement (standalone), service shape (sibling), thresholds (N≥5/N≥10), drift window (30d vs 60d), library (Chart.js), permissions (`gestionar resultados`), and missing index. Tasks phase will resolve PR-chain shape based on the size forecast above.

**Artifact files**: `openspec/changes/feedback-loop-from-descartados/proposal.md`
**Next phase**: `sdd-spec` (parallel to `sdd-design`)
