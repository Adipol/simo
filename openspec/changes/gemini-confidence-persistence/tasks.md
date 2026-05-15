# Tasks: Gemini Confidence Persistence

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~90 production + ~150 tests = ~240 total |
| Files touched | 6 |
| Work units (commit-sized) | 3 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending (single PR — no chain needed) |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| WU1 | DTO seam — `maxConfianza()` + unit tests | PR 1 | Foundation; all later work depends on this |
| WU2 | Service persistence + feature tests | PR 1 | Wires DTO into `persistirResultado()`; 4 test cases |
| WU3 | Backfill command + feature tests | PR 1 | New command + 6 test cases; idempotent |

---

## Phase 1: DTO Seam (Foundation)

- [x] 1.1 [TEST] `tests/Unit/Gemini/FiltroResultadoDTOTest.php` — create file, write `test_maxConfianza_returns_null_when_personas_empty`; assert `null`
- [x] 1.2 [TEST] Same file — `test_maxConfianza_returns_value_for_single_persona`; confianza=85 → 85
- [x] 1.3 [TEST] Same file — `test_maxConfianza_returns_max_for_multiple_personas`; [60,90,75] → 90
- [x] 1.4 [TEST] Same file — `test_maxConfianza_returns_zero_when_all_confianza_zero`; all-zero int DTO → 0
- [x] 1.5 [TEST] Same file — `test_maxConfianza_returns_max_of_non_zero_mixed`; [0,70,0,55] → 70
- [x] 1.6 [PROD] `app/Services/Gemini/DTOs/FiltroResultadoDTO.php` — add `maxConfianza(): ?int`; all 5 unit tests pass

---

## Phase 2: Service Persistence

- [x] 2.1 [TEST] `tests/Feature/Gemini/GeminiFiltroServiceTest.php` — add `test_persistir_resultado_writes_gemini_confianza_for_single_persona`; confianza=80 persisted
- [x] 2.2 [TEST] Same file — `test_persistir_resultado_writes_max_confianza_for_multiple_personas`; [50,95] → 95
- [x] 2.3 [TEST] Same file — `test_persistir_resultado_writes_null_confianza_when_zero_personas`; no personas → NULL
- [x] 2.4 [TEST] Same file — `test_pre_filter_rejection_leaves_gemini_confianza_null`; `gemini_analyzed=false` row untouched
- [x] 2.5 [PROD] `app/Services/Gemini/GeminiFiltroService.php` — add `'gemini_confianza' => $dto->maxConfianza()` to `persistirResultado()` update array; all 4 service tests pass

---

## Phase 3: Backfill Command

- [x] 3.1 [TEST] `tests/Feature/Commands/BackfillGeminiConfianzaTest.php` — create file, `test_updates_null_confianza_with_max_of_personas`; analyzed row with personas → updated
- [x] 3.2 [TEST] Same file — `test_skips_analyzed_row_with_no_personas`; no personas → remains NULL, skipped_no_personas++
- [x] 3.3 [TEST] Same file — `test_idempotent_second_run_updates_zero_rows`; re-run on already-populated rows → 0 updates
- [x] 3.4 [TEST] Same file — `test_unanalyzed_rows_not_touched`; `gemini_analyzed=false` row unmodified
- [x] 3.5 [TEST] Same file — `test_dry_run_reports_without_writing`; `--dry-run` → counts output, no DB mutation
- [x] 3.6 [TEST] Same file — `test_reports_four_counters_on_normal_run`; mixed fixture → scanned/updated/skipped_no_personas/mode all reported
- [x] 3.7 [PROD] `app/Console/Commands/BackfillGeminiConfianza.php` — create command: signature `simo:backfill-gemini-confianza {--dry-run}`, `chunkById(100)`, eager-load `personas`, PHP `max()`, table report; all 6 tests pass
- [x] 3.8 [PROD] Verify command auto-discovery works (Laravel 12 auto-discovers `app/Console/Commands/`); no manual kernel registration needed

---

## Phase 4: Verification

- [x] 4.1 Run `php artisan test` (SQLite) — full suite green (913 tests, 0 failures)
- [x] 4.2 Run `php artisan test --filter=Gemini` — 246 Gemini tests passing
- [x] 4.3 Mark all checkboxes above as completed

---

## Phase 5: Nits Remediation (verify-report WARNING 1 + 2)

- [x] T5.1 [TEST] `tests/Feature/Commands/BackfillGeminiConfianzaTest.php` — replace weak `expectsOutputToContain('2'/'1')` in `test_command_reports_correct_counters` with `expectsTable(...)` asserting all 5 rows including new 4th counter; RED confirmed (2 failures)
- [x] T5.2 [TEST] Same file — add `test_command_reports_skipped_already_populated_counter`; asserts 4th row = 0 even when already-populated rows exist in DB; RED confirmed
- [x] T5.3 [PROD] `app/Console/Commands/BackfillGeminiConfianza.php` — add `['Skipped (already populated)', 0]` row to output table; GREEN (7/7 backfill tests pass)
- [x] T5.4 Full suite: 914 tests, 0 failures, 0 errors (9 skipped + 1 incomplete pre-existing)
- [x] T5.5 Committed: `bb633d9` — `fix(gemini): match spec counter labels and harden backfill test assertions`
- [x] T5.6 Pushed: `git push origin feat/gemini-confidence-persistence` (regular push, 6011f63..bb633d9)
