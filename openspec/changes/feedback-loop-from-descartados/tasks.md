# Tasks: feedback-loop-from-descartados

**Change**: feedback-loop-from-descartados
**Phase**: tasks
**Date**: 2026-05-14
**Mode**: hybrid

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~920 (breakdown below) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested chain boundary | PR-A (migration + service + DTOs) → PR-B (CLI command) → PR-C (Livewire + view + route) |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Per-file estimate

| File | LOC | Type |
|---|---|---|
| `database/migrations/2026_05_20_120000_add_sitio_id_idx_to_resultados_scraping.php` | ~25 | NEW |
| `app/Services/Dashboard/DTOs/DescartadosMetricsDTO.php` | ~20 | NEW |
| `app/Services/Dashboard/DTOs/KeywordAnalisisDTO.php` | ~18 | NEW |
| `app/Services/Dashboard/DTOs/SitioAnalisisDTO.php` | ~18 | NEW |
| `app/Services/Dashboard/DTOs/DriftDTO.php` | ~18 | NEW |
| `app/Services/DescartadosAnalisisService.php` | ~160 | NEW |
| `app/Console/Commands/AnalizarDescartados.php` | ~75 | NEW |
| `app/Livewire/Admin/PrecisionDashboard.php` | ~55 | NEW |
| `resources/views/livewire/admin/precision-dashboard.blade.php` | ~130 | NEW |
| `routes/web.php` | ~5 | MOD |
| `tests/Feature/Migrations/SitioIdIndexMigrationTest.php` | ~35 | NEW |
| `tests/Feature/Services/DescartadosAnalisisServiceTest.php` | ~200 | NEW |
| `tests/Feature/Commands/AnalizarDescartadosTest.php` | ~120 | NEW |
| `tests/Feature/Livewire/PrecisionDashboardTest.php` | ~80 | NEW |
| **Total** | **~959** | |

**Suggested PR split (stacked-to-main):**

| PR | Contents | LOC | Independently mergeable |
|---|---|---|---|
| PR-A | Migration + 4 DTOs + DescartadosAnalisisService + service tests + migration test | ~494 | Yes — adds analytical capability, no UI |
| PR-B | AnalizarDescartados command + command tests | ~195 | Yes — depends on PR-A merged |
| PR-C | PrecisionDashboard Livewire + Blade view + route + Livewire tests | ~270 | Yes — depends on PR-A merged (parallel to PR-B) |

> PR-B and PR-C are independent of each other once PR-A is merged.
> **Decision needed**: Confirm chained PR strategy before sdd-apply begins.

---

## Resolved Design Decisions (deferred from design phase)

| Question | Decision |
|---|---|
| Migration timestamp | `2026_05_20_120000` — no collision with latest `2026_05_10_110002` |
| Extract `DescartadosReportFormatter`? | **No** — 5 private render methods stay inside command (~75 LOC total, under 100 threshold) |
| Split 4 charts into sub-components? | **No** — single Blade view stays ~130 LOC, under 200 threshold |
| Sidebar link in `layouts/app.blade.php`? | **Out of scope** — CLI-only first; UI link deferred to follow-up |
| `flushCache()` key sync | Use `private const array CACHED_KEY_SPECS` registry (method + default params combos) so `flushCache()` iterates the same list `cacheKey()` uses |

---

## Dependency Graph

```
Phase 1: Migration
    └── 1.1 (RED) → 1.2 (GREEN)
             │
Phase 2: DTOs (independent of migration, but prerequisite for service)
    └── 2.1 → 2.2 → 2.3 → 2.4 (all atomic, no cross-dependency)
             │
Phase 3: Service (depends on DTOs)
    └── 3.1(R)→3.2(G) → 3.3(R)→3.4(G) → 3.5(R)→3.6(G)
        → 3.7(R)→3.8(G) → 3.9(R)→3.10(G) ─────────────┐
                                                          ▼
                                                   ┌──── PR-A boundary
                                                   │
Phase 4: Command (depends on PR-A / Phase 3)       │
    └── 4.1(R)→4.2(G) → 4.3(R)→4.4(G)             │   ─── PR-B
                                                   │
Phase 5: Livewire (depends on PR-A / Phase 3)      │
    └── 5.1(R)→5.2(G) → 5.3(R)→5.4(G)             │   ─── PR-C (parallel to PR-B)
             │
Phase 6: Route + final wiring
    └── 6.1 (route) → 6.2 (smoke)
             │
Phase 7: Verification
    └── 7.1 → 7.2 → 7.3
```

---

## Phase 1: Database (migration)

> **PR-A start**. Establishes the `sitio_id` btree index needed for `topSitiosProblematicos` query performance.

- [x] **1.1 RED — Write migration test**
  - **File**: `tests/Feature/Migrations/SitioIdIndexMigrationTest.php`
  - Create `it_creates_btree_index_on_sitio_id_on_pgsql()` — skips with `markTestSkipped` when driver is not `pgsql`. Uses `Schema::hasIndex('resultados_scraping', 'idx_resultados_scraping_sitio_id')` (or raw `pg_indexes` query if helper unavailable).
  - **Run**: `php artisan test --filter=SitioIdIndexMigrationTest` → **FAIL** (migration not yet created)
  - **SCN**: REQ-8 / SCN-8.1, SCN-8.2, SCN-8.3

- [x] **1.2 GREEN — Create the migration**
  - **File**: `database/migrations/2026_05_20_120000_add_sitio_id_idx_to_resultados_scraping.php`
  - `public $withinTransaction = false`; driver-conditional: pgsql → `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, SQLite → `CREATE INDEX IF NOT EXISTS`. `down()` mirrors with `DROP INDEX CONCURRENTLY IF EXISTS` (pgsql) / `DROP INDEX IF EXISTS` (SQLite).
  - Pattern mirrors `2026_05_09_100003_add_titulo_trgm_index_to_resultados_scraping.php`.
  - **Run**: `php artisan test --filter=SitioIdIndexMigrationTest` → **PASS**

---

## Phase 2: DTOs (foundation)

> Prerequisite for service. All 4 are independent and can be committed in a single batch.
> Uses existing `app/Services/Dashboard/DTOs/` namespace — confirmed present.

- [x] **2.1 — Create `DescartadosMetricsDTO`**
  - **File**: `app/Services/Dashboard/DTOs/DescartadosMetricsDTO.php`
  - `final readonly class` with constructor promotion: `int $totalProcesados`, `int $totalDescartados`, `int $totalRelevantes`, `int $totalArchivados`, `?float $precisionPct`, `string $insufficientReason = ''`. Static `fromArray(array $row): self`.
  - **SCN**: REQ-1 / SCN-1.1 (data shape)

- [x] **2.2 — Create `KeywordAnalisisDTO`**
  - **File**: `app/Services/Dashboard/DTOs/KeywordAnalisisDTO.php`
  - `final readonly class`: `string $keyword`, `int $total`, `int $descartados`, `int $relevantes`, `float $pctDescartado`. Static `fromArray()`.
  - **SCN**: REQ-2 / SCN-2.1

- [x] **2.3 — Create `SitioAnalisisDTO`**
  - **File**: `app/Services/Dashboard/DTOs/SitioAnalisisDTO.php`
  - `final readonly class`: `int $sitioId`, `string $sitioNombre`, `int $total`, `int $descartados`, `float $pctDescartado`. Static `fromArray()`.
  - **SCN**: REQ-3 / SCN-3.1

- [x] **2.4 — Create `DriftDTO`**
  - **File**: `app/Services/Dashboard/DTOs/DriftDTO.php`
  - `final readonly class`: `string $keyword`, `?float $pctActual`, `?float $pctAnterior`, `?float $driftPpt`. Static `fromArray()`.
  - **SCN**: REQ-4 / SCN-4.1, SCN-4.3 (null paths)

---

## Phase 3: Service (core logic, TDD)

> Implements `DescartadosAnalisisService` method by method, RED-first. Each pair is one commit.
> Cache key registry: declare `private const array CACHED_KEY_SPECS` listing all 5 cached methods with default param arrays. Both `cacheKey()` and `flushCache()` iterate this same constant.

- [x] **3.1 RED — Write `precisionGeneral` tests (2 tests)**
  - **File**: `tests/Feature/Services/DescartadosAnalisisServiceTest.php`
  - `it_calculates_precision_correctly()` — seed 15 labeled (10 descartados, 5 relevantes) within window, assert `precisionPct ≈ 33.33` and all totals correct.
  - `it_returns_null_precision_when_below_min_global()` — seed 8 labeled, assert `precisionPct === null` and `insufficientReason` contains `"< 10"`.
  - Setup: `RefreshDatabase`, `ResultadoScraping::flushEventListeners()`, `Carbon::setTestNow()`.
  - **Run**: `php artisan test --filter=DescartadosAnalisisServiceTest` → **FAIL**
  - **SCN**: REQ-1 / SCN-1.1, SCN-1.2

- [x] **3.2 GREEN — Implement `precisionGeneral()`**
  - **File**: `app/Services/DescartadosAnalisisService.php` (create stub with all method signatures + `cacheKey()` + `flushCache()` + `CACHED_KEY_SPECS` constant)
  - Implement only `precisionGeneral()`: Eloquent `selectRaw` on `resultados_scraping` filtering `gemini_descartado IS NOT NULL OR gemini_relevante IS NOT NULL` within `$dias` window. Guard: if total < `$minGlobal` → return DTO with `precisionPct=null`. Cache: `Cache::remember("descartados:precision:{$dias}", self::CACHE_TTL, ...)` unless `$skipCache`.
  - **Run**: `php artisan test --filter=DescartadosAnalisisServiceTest` → **PASS** (only the 2 tests seeded so far)

- [x] **3.3 RED — Write `topLemasProblematicos` + `topSitiosProblematicos` tests (3 tests)**
  - `it_excludes_keywords_below_min_sample()` — seed 3 results under keyword "foo", assert it does not appear in results.
  - `it_ranks_lemas_by_descartado_percentage()` — seed 2 keywords with different rates, assert DESC order.
  - `it_joins_sitios_web_for_sitio_nombre()` — seed via factory with sitio relation, assert `SitioAnalisisDTO::sitioNombre` is populated.
  - **Run**: → **FAIL**
  - **SCN**: REQ-2 / SCN-2.1, SCN-2.2, SCN-2.3; REQ-3 / SCN-3.1, SCN-3.2

- [x] **3.4 GREEN — Implement `topLemasProblematicos()` + `topSitiosProblematicos()`**
  - Eloquent `selectRaw + groupBy('keyword') + havingRaw('COUNT(*) >= ?', [$minSample]) + orderByRaw('pct_descartado DESC') + limit($limit)`. Sitios version JOINs `sitios_web`. Map rows to DTOs via `fromArray()`. Cache per `CACHED_KEY_SPECS`.
  - **Run**: → **PASS**

- [x] **3.5 RED — Write `driftPorKeyword` tests (2 tests)**
  - `it_calculates_drift_between_windows()` — seed keyword with known rates in recent and previous windows, assert `driftPpt = pctActual - pctAnterior`.
  - `it_handles_empty_drift_window_gracefully()` — seed only recent window data, assert `pctAnterior=null` and `driftPpt=null`.
  - **Run**: → **FAIL**
  - **SCN**: REQ-4 / SCN-4.1, SCN-4.2, SCN-4.3

- [x] **3.6 GREEN — Implement `driftPorKeyword()`**
  - Use `DB::select(<<<SQL ... SQL)` with CTE joining two windows. Comment WHY raw: Eloquent CTE support too verbose (mirrors AGENTS.md exception clause). Map rows to `DriftDTO::fromArray()`. `pctAnterior=null` when previous window has no data for a keyword.
  - **Run**: → **PASS**

- [x] **3.7 RED — Write `confianzaGeminiVsDescartado` + `getNegativeExamples` + cache tests (5 tests)**
  - `it_buckets_confianza_correctly()` — seed results across 4 confidence ranges (0-49, 50-69, 70-84, 85-100), assert correct bucket counts and `pct` calculations.
  - `it_exposes_negative_examples_seam()` — seed high-confidence descartados (≥70), assert `getNegativeExamples()` returns them (NOT cached).
  - `it_caches_results_with_correct_ttl()` — call `precisionGeneral()` twice, assert DB query count = 1 (cache hit on second call). Use `DB::enableQueryLog()`.
  - `it_skips_cache_with_skip_cache_flag()` — call with `skipCache=true` twice, assert 2 DB queries.
  - `it_flushes_cache()` — prime cache, call `flushCache()`, assert next call hits DB again.
  - **Run**: → **FAIL**
  - **SCN**: REQ-5 / SCN-5.1, SCN-5.2, SCN-5.3; REQ-6 / SCN-6.1, SCN-6.2, SCN-6.3; REQ-7 / SCN-7.1, SCN-7.2

- [x] **3.8 GREEN — Implement `confianzaGeminiVsDescartado()` + `getNegativeExamples()` + `flushCache()`**
  - `confianzaGeminiVsDescartado()`: Eloquent `selectRaw` with `CASE WHEN gemini_confianza BETWEEN 85 AND 100 ...` for 4 buckets. Always returns 4 rows (N=0 for empty buckets). Cached.
  - `getNegativeExamples()`: `ResultadoScraping::where('gemini_descartado', true)->where('gemini_confianza', '>=', 70)->limit($limit)->get()`. NOT cached.
  - `flushCache()`: iterate `CACHED_KEY_SPECS`, generate key via `cacheKey()`, call `Cache::forget()` for each.
  - `cacheKey()`: `"descartados:{$method}:" . implode(':', $params)`.
  - **Run**: → **PASS** (all 10 service tests green)

> **PR-A checkpoint**: migration test + 10 service tests + 4 DTOs. Run full suite, confirm green, open PR-A to main.

---

## Phase 4: Command (T1)

> **PR-B** — depends on PR-A merged. All command tests RED before a single line of command code.

- [ ] **4.1 RED — Write `AnalizarDescartadosTest` (6 tests, all failing)**
  - **File**: `tests/Feature/Commands/AnalizarDescartadosTest.php`
  - `it_reports_precision_when_sufficient_sample()` — seed 15 labeled, run command, assert output contains precision % and section headers.
  - `it_reports_insufficient_data_when_below_threshold()` — seed 8 labeled, assert command outputs warning and exits 0.
  - `it_filters_by_categoria()` — seed mixed categorias, run `--categoria=PEP`, assert only PEP results counted.
  - `it_filters_by_keyword()` — seed mixed keywords, run `--keyword=corrupcion`, assert filtered output.
  - `it_respects_min_sample_flag()` — seed keyword with 3 results, run `--min-sample=5`, assert keyword absent from output.
  - `it_bypasses_cache_with_no_cache_flag()` — prime cache with stale data, run `--no-cache`, assert output reflects fresh DB data.
  - Mock `DescartadosAnalisisService` for filter tests to avoid data coupling. Use `$this->artisan('simo:analizar-descartados', [...])`.
  - **Run**: `php artisan test --filter=AnalizarDescartadosTest` → **FAIL** (command not registered)
  - **SCN**: precision-dashboard REQ-6 / SCN-6.1–6.8

- [ ] **4.2 GREEN — Implement `AnalizarDescartados` command**
  - **File**: `app/Console/Commands/AnalizarDescartados.php`
  - `final class AnalizarDescartados extends Command`. Signature as per design. `handle(DescartadosAnalisisService $service): int`. Five private renderers: `renderResumen()`, `renderLemas()`, `renderSitios()`, `renderDrift()`, `renderConfianza()`, `renderRecomendaciones()`. All use `$this->table()` / `$this->line()`. No `Gate::check()`. Document in `$description`.
  - Register in `app/Console/Kernel.php` or via auto-discovery (confirm which pattern the project uses).
  - **Run**: `php artisan test --filter=AnalizarDescartadosTest` → **PASS** (all 6 green)

> **PR-B checkpoint**: 6 command tests pass. Run `php artisan simo:analizar-descartados` locally against dev DB, confirm readable output.

---

## Phase 5: Livewire dashboard (T2)

> **PR-C** — depends on PR-A merged, independent of PR-B. Can be worked in parallel once PR-A lands.

- [ ] **5.1 RED — Write `PrecisionDashboardTest` (6 tests, all failing)**
  - **File**: `tests/Feature/Livewire/PrecisionDashboardTest.php`
  - `it_redirects_unauthenticated_users()` — `Livewire::test(PrecisionDashboard::class)` without auth, assert redirect to login.
  - `it_aborts_403_for_users_without_gestionar_resultados()` — `actingAs(user without permission)`, assert 403.
  - `it_renders_with_initial_data_for_admin()` — seed ≥10 labeled, `actingAs(admin)`, assert component renders with precision card, chart canvases present.
  - `it_refreshes_cache_when_button_clicked()` — prime cache, `callAction('refrescarAhora')`, assert cache miss on next DB call (use DB::queryLog).
  - `it_emits_chart_data_updated_after_refresh()` — `callAction('refrescarAhora')`, assert `chart-data-updated` event dispatched.
  - `it_shows_insufficient_data_message_when_empty()` — seed <10 labeled, `actingAs(admin)`, assert "datos insuficientes" message visible, no chart canvases.
  - Use `Livewire::actingAs($user)->test(PrecisionDashboard::class)`.
  - **Run**: `php artisan test --filter=PrecisionDashboardTest` → **FAIL** (class not found)
  - **SCN**: precision-dashboard REQ-1 / SCN-1.1–1.3; REQ-3 / SCN-3.1; REQ-4 / SCN-4.1; REQ-5 / SCN-5.1, SCN-5.2

- [ ] **5.2 GREEN — Implement `PrecisionDashboard` Livewire component**
  - **File**: `app/Livewire/Admin/PrecisionDashboard.php`
  - `final class PrecisionDashboard extends Component`. `mount()` → `abort_unless(auth()->user()?->can('gestionar resultados'), 403)`. Public `int $dias = 30`, `int $minSample = 5`. Five `#[Computed]` methods delegating to `DescartadosAnalisisService` (via `app()`). `refrescarAhora()` → `flushCache()`, `unset()` computed props, `$this->dispatch('chart-data-updated')`. `render()` → `view('livewire.admin.precision-dashboard')->layout('layouts.app', ['title' => 'Precision — Descartados'])`.
  - **Run**: `php artisan test --filter=PrecisionDashboardTest` → **PASS**

- [ ] **5.3 RED — Write view rendering test (inline with 5.1 or extend)**
  - Extend `PrecisionDashboardTest` with `it_renders_chart_grid_with_four_canvases()` — assert 4 `<canvas>` elements in rendered HTML.
  - **Run**: → **FAIL** (view not yet created)
  - **SCN**: precision-dashboard REQ-2 / SCN-2.1, SCN-2.2

- [ ] **5.4 GREEN — Create Blade view**
  - **File**: `resources/views/livewire/admin/precision-dashboard.blade.php`
  - Root `<div wire:poll.300s class="space-y-6">`. Header + "Refrescar ahora" button (`wire:click="refrescarAhora"`). Conditional: `@if ($this->precision->precisionPct === null)` → `<div class="simo-alert">{{ $this->precision->insufficientReason }}</div>` `@else` → `<x-precision-resumen :metrics="$this->precision" />`. 2x2 chart grid: 4 `<div x-data="*Chart(@js(...))" wire:ignore><canvas x-ref="canvas"></canvas></div>`. `@push('scripts')` with Chart.js 4.4.1 CDN + 4 Alpine chart factory functions + `Livewire.on('chart-data-updated', ...)` listener.
  - **Run**: → **PASS** (all 6 Livewire tests + canvas test green)

---

## Phase 6: Route + final wiring

> Part of PR-C. Add route last (after component class exists).

- [ ] **6.1 — Add route to `routes/web.php`**
  - Inside existing `Route::middleware(['auth', 'usuario.activo'])->group(...)` block.
  - Add `Route::get('/admin/precision', PrecisionDashboard::class)->middleware('permission:gestionar resultados')->name('admin.precision');`
  - Add `use App\Livewire\Admin\PrecisionDashboard;` import at top of file.
  - **Verify**: `php artisan route:list --name=admin.precision` shows the route with correct middleware stack.
  - **SCN**: precision-dashboard REQ-1 / SCN-1.1

- [ ] **6.2 — Manual smoke test**
  - `php artisan migrate` (confirm index created, no error on pgsql).
  - Navigate to `/admin/precision` as admin — confirm precision card and 4 chart canvases render.
  - Navigate as non-admin — confirm 403.
  - Navigate unauthenticated — confirm redirect to login.
  - Run `php artisan simo:analizar-descartados` against dev DB — confirm tabular output.

---

## Phase 7: Verification (post-implementation, pre-archive)

- [ ] **7.1 — Full test suite green**
  - `php artisan test` → all tests pass (including pre-existing suite).
  - Confirm no N+1 regressions (check `DB::getQueryLog()` in service tests; eager load `sitiosWeb` relation in `topSitiosProblematicos`).

- [ ] **7.2 — Coverage check**
  - Service: `DescartadosAnalisisService` covered by 10 tests → verify >85% coverage.
  - Command: 6 tests. Livewire: 6 tests + canvas test.

- [ ] **7.3 — PR-A opened, CI green**
  - Open PR-A (foundation) → merge → open PR-B and PR-C (parallel) → merge in any order.
  - Confirm `php artisan simo:analizar-descartados` and `/admin/precision` accessible on staging.

---

## Open questions resolved by tasks

| Question | Resolution |
|---|---|
| Migration timestamp | `2026_05_20_120000` (no collision) |
| Extract formatter | No — 5 private methods stay in command |
| Split charts | No — single Blade view ~130 LOC |
| Sidebar link | Out of scope (CLI-only first) |
| `flushCache()` key sync | `private const array CACHED_KEY_SPECS` with 5 method+params combos iterated by both `cacheKey()` and `flushCache()` |
| Confianza buckets | 4 ranges: 0-49, 50-69, 70-84, 85-100 (explicit in service tests so implementation has no ambiguity) |
| Command auto-discovery | Verify at apply time — check if project uses `Kernel.php::$commands` or auto-discovery (both patterns exist in Laravel 12) |

---

## Out of scope (NOT tasks — explicit list)

- **OUT-1**: T3 auto-feedback pipeline (getNegativeExamples seam is implemented but not consumed)
- **OUT-2**: New columns on `resultados_scraping` table
- **OUT-3**: Scraper changes
- **OUT-4**: descartar/archivar UX changes in Bandeja
- **OUT-5**: ML/embedding pipeline
- **OUT-6**: Email/Slack notifications on drift alerts
- **OUT-7**: Purge of old descartados data
- **OUT-8**: CSV/PDF export
- **OUT-9**: Extended drift windows (>60d)
- **OUT-10**: Multi-operator comparison
- **OUT-11**: Sidebar navigation link for `/admin/precision` (follow-up PR if user confirms UX need)
- **OUT-12**: `DescartadosReportFormatter` extract class
- **OUT-13**: Chart.js added to global `layouts/app.blade.php` (stay view-scoped)
