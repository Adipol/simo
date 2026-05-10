# Tasks: redesign-dashboard (Dashboard v2)

**Date**: 2026-05-10 · **Phase**: Tasks · **Model**: anthropic/claude-sonnet-4-6

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | PR1 ≈ 1 400–1 700 loc · PR2 ≈ 500–700 loc |
| 400-line budget risk | **PR1: High** · PR2: Medium |
| Chained PRs recommended | **Yes** |
| Suggested split | PR1 → PR2 (sequential, stacked-to-main) |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| PR1 | Dashboard v2 UI + Services (zero migrations) | PR1 | Base: main; includes all DTOs, services, cache manager, Blade components, Livewire refactor, CSS vars |
| PR2 | Pipeline instrumentation | PR2 | Base: PR1 branch (after merge to main); 4 migrations + GeminiService updates + real health data |

---

## Phase 1 — Foundation DTOs (PR1 · Block A)

All are pure classes — no DB, no framework deps. RED first, GREEN second.

- [x] T1 RED `tests/Unit/Services/Dashboard/HeroCardDTOTest.php` — `fromArray()` happy path; missing required field throws `\InvalidArgumentException`
- [x] T2 GREEN `app/Services/Dashboard/DTOs/HeroCardDTO.php` — typed readonly class, static `fromArray()`
- [x] T3 RED `tests/Unit/Services/Dashboard/TriageStripDTOTest.php` — 4 risk buckets + sparkline array shape
- [x] T4 GREEN `app/Services/Dashboard/DTOs/TriageStripDTO.php`
- [x] T5 RED `tests/Unit/Services/Dashboard/BacklogAgeDTOTest.php` — threshold + count fields
- [x] T6 GREEN `app/Services/Dashboard/DTOs/BacklogAgeDTO.php`
- [x] T7 RED `tests/Unit/Services/Dashboard/RecentDiscoveriesDTOTest.php` — `CambioSummary` sub-DTO + max-5 contract
- [x] T8 GREEN `app/Services/Dashboard/DTOs/RecentDiscoveriesDTO.php` + `CambioSummary.php`
- [x] T9 RED `tests/Unit/Services/Dashboard/DashboardSummaryDTOTest.php` — composite assembly from child DTOs
- [x] T10 GREEN `app/Services/Dashboard/DTOs/DashboardSummaryDTO.php`
- [x] T11 RED `tests/Unit/Services/Dashboard/HealthDTOsTest.php` — `PipelineHealthDTO`, `ScraperStatusDTO`, `QueueDepthDTO`, `LatencyDTO` (`available:false` stub), `GeminiQuotaDTO` (`available:false` stub)
- [x] T12 GREEN `app/Services/Dashboard/DTOs/{PipelineHealthDTO,ScraperStatusDTO,QueueDepthDTO,LatencyDTO,GeminiQuotaDTO}.php`
- [x] T13 RED `tests/Unit/Services/Dashboard/HeatmapPaletteTest.php` — table-driven: 0→grey, quintile boundaries, 10 ISO countries
- [x] T14 GREEN `app/Services/Dashboard/HeatmapPalette.php`

## Phase 2 — Config + Cache Manager (PR1 · Block B)

- [x] T15 GREEN `config/dashboard.php` — `hero_formula` weights, `backlog_aging_days`, `summary_cache_ttl`, `health_cache_ttl`; no RED needed (config values covered by service tests)
- [x] T16 RED `tests/Unit/Services/Dashboard/DashboardCacheManagerTest.php` — get/set per key, bust by event (hero/triage/backlog/ultima keys forgotten)
- [x] T17 GREEN `app/Services/Dashboard/DashboardCacheManager.php` — wraps `Cache::remember` per metric key; `bust(string ...$keys)` public method; `bustSummaryOnRevisado()` convenience

## Phase 3 — DashboardSummaryService (PR1 · Block C)

- [x] T18 RED `tests/Feature/Services/Dashboard/DashboardSummaryServiceTest.php` scenario: `getSnapshot()` returns full DTO — hero card, triage, backlog, recentDiscoveries
- [x] T19 RED same file scenario: empty DB → heroCard=null, triage all zeros, sparklines=[0,0,0,0,0,0,0], "Todo al día" path
- [x] T20 RED same file scenario: hero scoring formula — riesgo_alto×3, es_mae×2, días_pendiente÷3; tie broken by id DESC
- [x] T21 RED same file scenario: cache hit — second `getSnapshot()` adds 0 extra queries (use `DB::enableQueryLog()`)
- [x] T22 RED same file scenario: cache bust on `CambioRevisado` dispatched → hero/triage/backlog/ultima keys evicted
- [x] T23 GREEN `app/Services/Dashboard/DashboardSummaryService.php` — `getSnapshot()`, per-metric cache keys via `DashboardCacheManager`, SQL hero scoring with JSONB `gemini_analisis_json->>'riesgo'`, ORDER BY score DESC, fecha DESC, id DESC LIMIT 1
- [x] T24 REFACTOR extract sparkline 7-day computation into private `computeSparkline()` if shared with triage

## Phase 4 — DashboardHealthService (PR1 · Block D)

- [x] T25 RED `tests/Feature/Services/Dashboard/DashboardHealthServiceTest.php` scenario: `getHealth()` → `latency->available=false`, `geminiQuota->available=false` (PR1 stubs)
- [x] T26 RED same file scenario: scraper + pep_monitor status via `LogScript::ultimaEjecucion()` (45min→ok, no entry→sin_registros, 8h→warning)
- [x] T27 RED same file scenario: queue depth from `jobs` table grouped by queue name (3+5+2 → correct counts; empty → all zeros, available=true)
- [x] T28 RED same file scenario: `canSeeDetails=false` for operator; `canSeeDetails=true` for admin/supervisor; defaults false when unauthenticated
- [x] T29 GREEN `app/Services/Dashboard/DashboardHealthService.php` — `getHealth(?User $user)`, single cache key `dashboard:health` (15s) for scraper+queue; `canSeeDetails` resolved AFTER cache fetch from `Auth::user()->hasAnyRole()`

## Phase 5 — Livewire Dashboard Refactor (PR1 · Block E)

- [x] T30 RED `tests/Feature/Livewire/DashboardTest.php` scenario: render() produces 0 direct Eloquent queries (DB::listen)
- [x] T31 RED same file scenario: `filtroDateRange`, `filtroPais`, `filtroCategoria` with `#[Url]` persist in URL on reload
- [x] T32 RED same file scenario: cold-cache wire:poll cycle executes ≤ 15 queries total
- [x] T33 RED same file scenario: `marcarRevisado` action dispatches bust; cache keys evicted
- [x] T34 GREEN `app/Livewire/Dashboard.php` — inject `DashboardSummaryService` + `DashboardHealthService` via constructor; add `#[Url]` to 3 filter props; `#[Computed]` for `summary()` and `health()`; remove all inline Eloquent calls
- [x] T35 GREEN add `#[Url]` to `filtroDateRange`, `filtroPais`, `filtroCategoria`; verify `resetPage()` on filter change
- [x] T36 GREEN `resources/views/livewire/dashboard.blade.php` — slim orchestrator with `@island('action')` wire:poll.30s, `@island('health')` wire:poll.15s, `@island('discovery')` wire:poll.60s
- [x] T37 REFACTOR ensure no logic in `render()`; all derived data via `#[Computed]`

## Phase 6 — Blade Sub-components (PR1 · Block F)

- [x] T38 RED `tests/Feature/View/Dashboard/ActionLayerTest.php` — hero card renders with risk badge + "Revisar ahora" link; null hero → "Todo al día ✓" empty state
- [x] T39 RED `tests/Feature/View/Dashboard/HealthStripTest.php` — colored pills for all users; numeric detail absent from DOM for non-admin (assertDontSee)
- [x] T40 RED `tests/Feature/View/Dashboard/DiscoveryLayerTest.php` — avatars + confidence color + risk badges; empty → teaching message
- [x] T41 RED `tests/Feature/View/Dashboard/SparklineTest.php` — SVG rendered with exactly 7 polyline points; all-zero → flat line (no error)
- [x] T42 RED `tests/Feature/View/Dashboard/LatamHeatmapTest.php` — 10 ISO country paths present; AR count=12 → dark fill; CO=0 → grey fill; all-zero → grey overlay
- [x] T43 GREEN `resources/views/components/dashboard/action-layer.blade.php` + `hero-card.blade.php` + `triage-strip.blade.php`/`triage-card.blade.php`
- [x] T44 GREEN `resources/views/components/dashboard/health-strip.blade.php` + `health-pill.blade.php`; `@if($health->canSeeDetails)` guards (NOT `class="hidden"`)
- [x] T45 GREEN `resources/views/components/dashboard/discovery-layer.blade.php` + `recent-pep-card.blade.php` + `recent-cambio-card.blade.php`
- [x] T46 GREEN `resources/views/components/simo-sparkline.blade.php` — pure inline SVG, 84×26 viewBox, polyline with computed points, ZERO JS
- [x] T47 GREEN `resources/views/components/dashboard/latam-heatmap.blade.php` — 10 ISO inline SVG paths, `<title>` hover, 5 quintile buckets via `HeatmapPalette::colorFor()`
- [x] T48 GREEN `resources/views/components/dashboard/analytics-section.blade.php` — `@can('ver dashboard estadisticas')` guard; Alpine `x-data/x-show` toggle
- [x] T49 REFACTOR extract shared sub-components surfaced during T43–T48

## Phase 7 — CSS Variables (PR1 · Block G)

- [x] T50 GREEN `resources/css/app.css` — add `:root { --simo-bg; --simo-surface; --simo-surface-muted; --simo-border; --simo-text-primary; --simo-text-secondary; --simo-text-muted; --simo-accent; --simo-danger; --simo-warning; --simo-success; }`; NO dark: block; NO existing class migration

## Phase 8 — Permission + Integration Tests (PR1 · Blocks H+I)

- [ ] T51 RED `tests/Feature/Services/Dashboard/DashboardHealthPermissionTest.php` — operator role: queue depth numbers absent from rendered HTML
- [ ] T52 RED same file — admin role: full health strip details present
- [ ] T53 GREEN verify `@if` guards in health-strip blade (fix if needed; not `class="hidden"`)
- [ ] T54 RED `tests/Feature/Livewire/DashboardIntegrationTest.php` — full dashboard load ≤ 15 queries total (define N=15 from design)
- [ ] T55 GREEN `database/migrations/2026_05_10_000001_add_dashboard_indexes_to_cambios.php` — `(revisado, fecha)` composite index + partial index `WHERE revisado=false` on `(revisado, gemini_analyzed, fecha)`; verify `(leido)` on resultados_scraping + `(queue)` on jobs exist
- [ ] T56 RED `tests/Feature/Livewire/DashboardSparklineTest.php` — sparkline data has exactly 7 elements
- [ ] T57 GREEN enforce 7-element output in `DashboardSummaryService::computeSparkline()` via `array_pad()` or equivalent

## Phase 9 — PR1 Final Polish (Block J)

- [ ] T58 REFACTOR all PR1 PHP files: add `declare(strict_types=1)`; `final class` on DTOs + services + HeatmapPalette; typed params and return types everywhere
- [ ] T59 REFACTOR run pre-commit hook manually; fix any AI-review issues WITHOUT `--no-verify`

---

## Phase 10 — PR2 Migrations (Block K)

- [x] T60 GREEN `database/migrations/2026_05_10_100001_add_gemini_analyzed_at_to_cambios_table.php` — nullable timestamp + index; `down()` drops column
- [x] T61 GREEN `database/migrations/2026_05_10_100002_add_gemini_analyzed_at_to_resultados_scraping_table.php` — nullable timestamp + index
- [x] T62 GREEN `database/migrations/2026_05_10_100003_add_revisado_at_to_cambios_table.php` — nullable timestamp; backfill with `fecha` WHERE revisado=true; `down()` drops column
- [x] T63 GREEN `database/migrations/2026_05_10_100004_create_gemini_usage_log_table.php` — columns: id, model, prompt_tokens (nullable), completion_tokens (nullable), total_tokens (nullable), request_type, cambio_id (nullable FK), resultado_scraping_id (nullable FK), created_at; indexes on created_at, [model,created_at], request_type

## Phase 11 — Gemini Service Updates (PR2 · Block L)

- [x] T64 RED `tests/Feature/Gemini/GeminiAnalisisServiceUsageLogTest.php` — happy path writes timestamp + usage_log row with token counts
- [x] T65 RED same file — error path: no timestamp, no usage_log row
- [x] T66 RED same file — missing usageMetadata: null tokens, Log::warning, analysis NOT aborted
- [x] T67 RED same file — idempotency: gemini_analyzed_at IS NOT NULL skips API call
- [x] T68 GREEN `app/Services/Gemini/GeminiAnalisisService.php` — GeminiResponseDTO + sendWithMetadata(), write gemini_analyzed_at, insert gemini_usage_log, idempotency guard; multimodal → request_type='analisis_multimodal'
- [x] T69 RED `tests/Feature/Gemini/GeminiFiltroServiceUsageLogTest.php` — same 4 scenarios for filtro
- [x] T70 GREEN `app/Services/Gemini/GeminiFiltroService.php` — same instrumentation; request_type='filtro'; sets resultados_scraping.gemini_analyzed_at

## Phase 12 — Health Service Real Data (PR2 · Block M)

- [x] T71 RED `tests/Feature/Services/Dashboard/DashboardHealthServiceLatencyTest.php` — pipelineLatency() unavailable when sample_size < 10
- [x] T72 RED same file — computes p50/p95 with realistic data (≥10 samples)
- [x] T73 RED `tests/Feature/Services/Dashboard/DashboardHealthServiceQuotaTest.php` — geminiQuota() aggregates today's tokens from gemini_usage_log
- [x] T74 RED same file — returns unavailable when no requests today
- [x] T75 GREEN `app/Services/Dashboard/DashboardHealthService.php` — implemented pipelineLatency() with percentile_cont/julianday dual driver + geminiQuota() from gemini_usage_log; switched stubs to real data

## Phase 13 — UI Activation PR2 (Block N)

- [x] T76 VERIFIED `resources/views/components/dashboard/health-strip.blade.php` — latency->available:false → "Recolectando datos…" already implemented (pre-existing from PR1.3)
- [x] T77 VERIFIED same — latency->available:true → P50/P95 numbers already implemented
- [x] T78 VERIFIED — HealthStripTest.php confirms conditional rendering works (6 tests pass)

## Phase 14 — PR2 Final Polish (Block O)

- [x] T79 REFACTOR pint run on all new files; strict_types + final + type hints audit complete; Guardian PASSED on all 7 commits
- [x] T80 REFACTOR revisado_at backfill confirmed in migration; gemini_analyzed_at indexes present in both migrations; all 772 tests passing

---

## Dependency Graph — Critical Path

```
T1→T2→T3→T4→T5→T6→T7→T8→T9→T10→T11→T12
T13→T14
T15→T16→T17
T18-T22 (parallel RED tests, all depend on T10+T12+T17)
T23 (GREEN — depends on T18-T22 passing RED)
T24 (REFACTOR — depends on T23)
T25-T28 (parallel RED — depends on T12+T17)
T29 (GREEN — depends on T25-T28)
T30-T33 (parallel RED — depends on T23+T29)
T34→T35→T36 (GREEN — depends on T30-T33)
T37 (REFACTOR — depends on T34-T36)
T38-T42 (parallel RED — depends on T10+T12)
T43-T48 (GREEN — depends on T38-T42 + T34-T36)
T49 (REFACTOR)
T50 (independent, can run parallel with T43-T48)
T51-T53 (depends on T29+T44)
T54 (depends on T34-T36 + T55)
T55 (independent migration, no RED needed)
T56→T57 (depends on T23)
T58-T59 (final REFACTOR, depends on all above)

PR2 critical path:
T60→T61→T62→T63
T64-T67 (parallel RED — depends on T60+T63)
T68 (GREEN — depends on T64-T67)
T69→T70 (depends on T61+T63)
T71-T74 (parallel RED — depends on T60+T63+T68+T70)
T75 (GREEN — depends on T71-T74)
T76-T77 (RED — depends on T75)
T78 (GREEN — depends on T76-T77)
T79-T80 (REFACTOR — final)
```

### Parallel opportunities
- T1–T13 can be split across sessions: DTO tests are fully independent
- T25–T28 and T30–T33 can run in parallel once T17 is done
- T38–T42 (Blade component RED tests) can run in parallel with T30–T33
- T60–T63 (migrations) are independent of each other

---

## TDD Ordering Verification

Every production class has a RED task with ID ≤ its GREEN task:

| Production file | RED task | GREEN task |
|----------------|----------|------------|
| HeroCardDTO | T1 | T2 |
| TriageStripDTO | T3 | T4 |
| BacklogAgeDTO | T5 | T6 |
| RecentDiscoveriesDTO+CambioSummary | T7 | T8 |
| DashboardSummaryDTO | T9 | T10 |
| Health DTOs (5) | T11 | T12 |
| HeatmapPalette | T13 | T14 |
| DashboardCacheManager | T16 | T17 |
| DashboardSummaryService | T18-T22 | T23 |
| DashboardHealthService (PR1) | T25-T28 | T29 |
| Dashboard.php refactor | T30-T33 | T34-T36 |
| Blade action-layer | T38 | T43 |
| Blade health-strip | T39 | T44 |
| Blade discovery-layer | T40 | T45 |
| simo-sparkline | T41 | T46 |
| latam-heatmap | T42 | T47 |
| Permission guards | T51-T52 | T53 |
| Index migration (PR1) | T54 | T55 |
| Sparkline 7-element | T56 | T57 |
| GeminiAnalisisService | T64-T67 | T68 |
| GeminiFiltroService | T69 | T70 |
| DashboardHealthService (PR2) | T71-T74 | T75 |
| Health strip UI PR2 | T76-T77 | T78 |

✅ All production code tasks preceded by a failing-test task.

---

## Pre-commit Hook Compliance

- T58 enforces `declare(strict_types=1)` + `final class` + full type hints → hook AI-review passes
- T59 is a blocking REFACTOR that runs the hook and fixes issues before PR is opened
- No `--no-verify` is ever acceptable; T59 must green before apply is declared done
