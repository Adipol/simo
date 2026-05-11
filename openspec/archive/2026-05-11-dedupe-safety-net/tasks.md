# Tasks: dedupe-safety-net

**Change**: dedupe-safety-net
**Phase**: tasks
**Date**: 2026-05-10
**Mode**: hybrid

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~410 (breakdown below) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No (borderline — single PR acceptable) |
| Suggested split | Single PR |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: stacked-to-main
400-line budget risk: Medium

### Line estimate by file

| File | Estimated lines |
|------|----------------|
| Migration (`2026_05_10_110002_add_dedupe_processed_at...php`) | ~30 |
| `ResultadoScraping` model (fillable + cast) | ~3 |
| `DedupeArticulosService` (stamp in 2 paths) | ~10 |
| `DedupeArticulosServiceTest` (3 new tests + migration test) | ~100 |
| `DeduparPendientes.php` (new command) | ~50 |
| `DeduparPendientesCommandTest.php` (new, 5 tests) | ~180 |
| `routes/console.php` (schedule entry) | ~5 |
| `DEPLOY.md` (supervisor block + ops checklist) | ~35 |
| **Total** | **~413** |

**Note**: Estimate is borderline 400. Risk is Medium — single PR is safe given the change is purely additive. No decision needed before apply.

---

## Migration test location — decision

**Resolved here**: Migration test (`it_adds_dedupe_processed_at_column_with_partial_index`) lives in `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php` alongside the existing service tests.

**Rationale**: The file already requires PostgreSQL (has `skipIfNotPgsql()`), uses `RefreshDatabase`, and tests the same data layer. No `tests/Feature/Database/` folder exists — creating one for a single test adds nav friction without benefit. The migration assertion is a data-model concern collocated with the service it serves.

---

## Dependency Graph (ASCII)

```
T1 (migration) ──────────────────────────────────────────┐
                                                          ▼
T2 (model fillable+cast) ──────────────────────────────► T3 (migration test RED)
                                                          │
                                             T3 → T4 (migration GREEN — run artisan migrate)
                                                          │
                              ┌────────────────────────── ▼
T5 (service test RED: stamp success path)                 │
T6 (service test RED: stamp early-exit)      ◄────────────┘
T7 (service test RED: no-stamp when null)
                   │
                   ▼
T8 (service GREEN: modify procesar() — stamp in both paths)
                   │
                   ▼
T9  (command test RED: only null rows dispatched)
T10 (command test RED: kill switch → 0 jobs)
T11 (command test RED: no rows → 0 jobs)
T12 (command test RED: logs dispatch count)
T13 (command test RED: dispatches to dedupe queue)
                   │
                   ▼
T14 (command GREEN: create DeduparPendientes.php)
                   │
                   ▼
T15 (schedule entry in routes/console.php)
T16 (verify php artisan schedule:list shows simo:dedupar-pendientes)
                   │
                   ▼
T17 (DEPLOY.md: simo-dedupe-worker supervisor block + ops checklist)
```

---

## Phase 1: Database

- [x] **T1 (GREEN)**: Create `database/migrations/2026_05_10_110002_add_dedupe_processed_at_to_resultados_scraping_table.php` — add `timestamp('dedupe_processed_at')->nullable()->default(null)`; on pgsql: `CREATE INDEX resultados_scraping_dedupe_pending_idx ON resultados_scraping (id) WHERE dedupe_processed_at IS NULL`; `down()` drops index (pgsql only) then column. *(satisfies SCN-1.1, SCN-4.1)*

- [x] **T2 (GREEN)**: Modify `app/Models/ResultadoScraping.php` — add `'dedupe_processed_at'` to `$fillable` and `'dedupe_processed_at' => 'datetime'` to `$casts`. *(satisfies SCN-1.1)*

---

## Phase 2: Service — RED → GREEN

- [x] **T3 (RED)**: In `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php`, add `test_it_adds_dedupe_processed_at_column_with_partial_index()` — assert column exists via `Schema::hasColumn('resultados_scraping', 'dedupe_processed_at')`; on pgsql assert `pg_indexes` row for `resultados_scraping_dedupe_pending_idx` (skip on SQLite). *(satisfies SCN-4.1)*

- [x] **T4 (GREEN)**: Run `php artisan migrate` (or `php artisan test` with `RefreshDatabase`) — confirm T3 passes. No code change needed; this is the green gate for the migration.

- [x] **T5 (RED)**: In `DedupeArticulosServiceTest`, add `test_it_sets_dedupe_processed_at_after_successful_processing()` — row with `dedupe_processed_at=NULL`, call `procesar()`, assert `dedupe_processed_at IS NOT NULL` after refresh. Works on SQLite (no candidates → no cluster, but still stamps). *(satisfies SCN-1.2, REQ-1)*

- [x] **T6 (RED)**: In `DedupeArticulosServiceTest`, add `test_it_sets_dedupe_processed_at_even_when_row_is_already_secondary()` — row with `secundario_de` already set + `dedupe_processed_at=NULL`, call `procesar()`, assert `dedupe_processed_at IS NOT NULL` after refresh. *(satisfies SCN-1.3, REQ-1)*

- [x] **T7 (RED)**: In `DedupeArticulosServiceTest`, add `test_it_does_not_stamp_when_article_does_not_exist()` — call `procesar(99999)`, assert no DB error and returns void cleanly (`expectNotToPerformAssertions()` is already covered; assert no stamp side-effect). *(satisfies design: null guard)*

- [x] **T8 (GREEN)**: Modify `app/Services/Dedupe/DedupeArticulosService::procesar()` — two edits:
  - **Path A** (early-exit at line 45): before the `return`, add `$article->update(['dedupe_processed_at' => now()])` (only when `$article !== null`; null branch stays as-is).
  - **Path B** (after `DB::transaction()` closes at line 82): add `$article->update(['dedupe_processed_at' => now()])` outside the transaction block.
  Run `php artisan test tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php` — T5, T6, T7 must go GREEN. *(satisfies SCN-1.2, SCN-1.3, REQ-1)*

---

## Phase 3: Command — RED → GREEN

- [x] **T9 (RED)**: Create `tests/Feature/Dedupe/DeduparPendientesCommandTest.php` with `RefreshDatabase` + `Queue::fake()` + helper `makeResultado(array $overrides=[])` (mirrors `AnalizarGeminiCommandTest`). Add test `test_it_dispatches_jobs_only_for_rows_with_null_dedupe_processed_at()` — create 2 rows: 1 with `dedupe_processed_at=NULL`, 1 with `dedupe_processed_at=now()`; run command; assert exactly 1 `DedupeArticulosJob` pushed. *(satisfies SCN-2.1, SCN-2.4, REQ-2)*

- [x] **T10 (RED)**: Add `test_it_respects_the_kill_switch_when_dedupe_is_disabled()` — `config(['services.dedupe.enabled' => false])`, create pending row, run command, assert `Queue::assertNothingPushed()` + `expectsOutputToContain('deshabilitado')` + exit 0. *(satisfies SCN-2.2, SCN-5.2, REQ-5)*

- [x] **T11 (RED)**: Add `test_it_does_not_dispatch_when_no_pending_rows_exist()` — enable kill switch, no rows, run command, assert `Queue::assertNothingPushed()` + exit 0. *(satisfies SCN-2.3)*

- [x] **T12 (RED)**: Add `test_it_logs_the_count_of_dispatched_jobs()` — create 3 pending rows, run command, assert `expectsOutputToContain('Dispatched 3 dedupe jobs.')`. *(satisfies SCN-6.1, REQ-6)*

- [x] **T13 (RED)**: Add `test_it_dispatches_to_the_dedupe_queue()` — create 1 pending row, run command, assert `Queue::assertPushed(DedupeArticulosJob::class, fn($j) => $j->queue === 'dedupe')`. *(satisfies SCN-2.1, SCN-6.2, REQ-3)*

- [x] **T14 (GREEN)**: Create `app/Console/Commands/DeduparPendientes.php` — `declare(strict_types=1)`, implements `handle(): int`, signature `simo:dedupar-pendientes`. Logic per design §5 pseudocode: kill-switch check → `whereNull('dedupe_processed_at')->pluck('id')` → `foreach DedupeArticulosJob::dispatch($id)` → output count → return `self::SUCCESS`. Register in `bootstrap/providers.php` or `app/Console/Kernel.php` if needed (Laravel 12 auto-discovery via `app/Console/Commands/` suffices). Run full test file — T9–T13 must go GREEN. *(satisfies REQ-2, REQ-5, REQ-6)*

---

## Phase 4: Schedule

- [x] **T15**: Modify `routes/console.php` — after the `simo:analizar-gemini` block (lines 17-21), insert the dedupe schedule entry per design §6:
  ```php
  // Dedupe: dispatch jobs para registros pendientes (safety net para Python)
  Schedule::command('simo:dedupar-pendientes')
      ->everyFiveMinutes()
      ->withoutOverlapping()
      ->onOneServer();
  ```
  *(satisfies REQ-2)*

- [x] **T16**: Run `php artisan schedule:list` — confirm `simo:dedupar-pendientes` appears with `Every 5 minutes` frequency. No test; visual confirmation.

---

## Phase 5: Documentation / Ops

- [x] **T17**: Modify `DEPLOY.md` — insert `[program:simo-dedupe-worker]` supervisor block AFTER `[program:simo-gemini-worker]` (line 235) and BEFORE `[program:simo-pep-monitor]` (line 236). Include full INI block (`--queue=dedupe`, `dedupe-worker.log`) plus ops checklist: `sudo supervisorctl reread` → `update` → `start simo-dedupe-worker:*` → verify with `sudo supervisorctl status simo-dedupe-worker` + `ps aux | grep dedupe`. *(satisfies SCN-3.3, REQ-3)*

---

## Phase 6: Verification (post-apply, before archive)

- [x] **T18**: Run full suite `php artisan test` — zero regressions, all new tests pass.

- [x] **T19**: Manual smoke on VPS: deploy, run `php artisan simo:dedupar-pendientes`, check `failed_jobs` for `queue='dedupe'`, observe `dedupe_processed_at` populating in DB.

- [x] **T20 (GREEN-only)**: Add `tests/Feature/Dedupe/DedupeConfigDefaultTest.php` — regression guard for SCN-5.1. Asserts `config/services.php:38-40` defaults `services.dedupe.enabled` to `true` when `DEDUPE_ENABLED` env var is not set. Skips artificial RED step — config already correct; closing verify-report #868 coverage gap. *(satisfies SCN-5.1, REQ-5)*

---

## Open questions resolved here

| Question | Resolution |
|----------|-----------|
| Migration test location | Inline in `DedupeArticulosServiceTest.php` (see rationale above) |
| Column position | Postgres appends; cosmetic; no override needed |
| Queued-monitoring helper | Out of scope — separate change if needed |

## Out of scope (not tasks)

- Backfill command — safety net IS the backfill (NULL default covers all historical rows)
- Horizon installation
- Retrying 133 pre-existing Gemini `failed_jobs`
- Changing similarity threshold or window
- `--limit` cap on dispatch count (revisit at >10k pending)
