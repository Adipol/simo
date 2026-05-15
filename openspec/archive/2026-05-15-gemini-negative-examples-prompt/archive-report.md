# Archive Report — gemini-negative-examples-prompt

**Change**: `gemini-negative-examples-prompt`  
**Archive Date**: 2026-05-15  
**Status**: COMPLETED  
**PR**: #39  
**Merge Commit**: `cc51c3e`  
**Branch Deleted**: `feat/gemini-negative-examples-prompt`

---

## Executive Summary

This SDD delivered dynamic negative-example injection into the Gemini filter prompt, closing a critical feedback loop: production data showed Gemini was over-permissive (87.5% human descarte in confidence bucket 85–100), so we injected real operator-rejected articles as few-shot examples. Merge commit `cc51c3e` contains all 8 implementation commits. CI (both SQLite and PostgreSQL) passed 927 tests with 0 failures. The change is production-ready and rolls back with a single env flag.

---

## Outcome

**What this SDD delivered**:
- Extended `GeminiPromptBuilder` with optional constructor injection of `DescartadosAnalisisService` and configurable limit (default 5) for negative examples.
- `buildEjemplosNegativos()` now routes between dynamic and hardcoded fallback based on service injection, flag state, and DB availability.
- Per-instance cache ensures exactly 1 DB call per builder lifetime (singleton pattern kept; staleness bounded by worker max-lifetime < 24h).
- Env flag `GEMINI_NEGATIVE_EXAMPLES_ENABLED` provides zero-deploy rollback.
- Injection count logging (`Log::info('gemini.negative_examples.injected', ['count' => N])`) for monitoring.
- All 32 existing `GeminiPromptBuilderTest` tests pass without modification; +13 new tests added (10 main + 3 quote-fix).

**Motivating data**:
- 87.5% descarte rate in confidence bucket 85–100 indicates miscalibration.
- Real operator rejections provide better calibration signal than hardcoded fictional examples.
- Expected outcome: improved precision in high-confidence range post-deploy.

---

## Decisions Log

All decisions from proposal, design, and verify phases are locked:

1. **Interface Extraction (Design Phase Discovery #997)**: `GeminiPromptBuilder` is registered as singleton in `AppServiceProvider.php:26`. Decision: KEEP singleton + accept bounded staleness. Per-instance `?Collection $cachedExamples` becomes per-process cache. Mitigation ready: switch to `bind()` in AppServiceProvider (1-line change) if staleness becomes a problem. ✅

2. **Env Flag Kill Switch**: `GEMINI_NEGATIVE_EXAMPLES_ENABLED` (default `true` prod, `false` test) allows revert without code deploy. Provides fast rollback path. ✅

3. **Logging Strategy**: Log COUNT ONLY on injection, no content (PII risk). Channel: `gemini.negative_examples.injected`. No entry on fallback. ✅

4. **Format Compatibility**: Dynamic `[NEG-OP]` entries use identical schema to hardcoded ones. Tag `[NEG-OP]` (vs `[NEG]`) signals operator-rejected for A/B analysis. ✅

5. **JSON Encoding Strategy (Fix Commit `c485259`)**: Use `json_encode(..., JSON_UNESCAPED_UNICODE)` for both titulo and motivo to safely escape quotes and backslashes while preserving Spanish accented characters. More robust than `addslashes`; makes payload structurally valid JSON. ✅

6. **Backward Compatibility**: Constructor accepts null service and existing `?PepCatalogService` pattern unchanged. `phpunit.xml` test-env flag=false guarantees all 32 pre-existing tests see hardcoded fallback. ✅

7. **Singleton Binding**: Updated singleton closure in `AppServiceProvider.php:26` injects both `PepCatalogService` (existing) and new `DescartadosAnalisisService` + limit config. ✅

---

## Metrics

| Metric | Value |
|--------|-------|
| Requirements (delta) | 6 (REQ-5 through REQ-10) |
| Total Requirements (canonical) | 10 (REQ-1 through REQ-10) |
| Scenarios Added | 17 |
| Total Test Count (final suite) | 927 |
| Tests Added (main + quote-fix) | 13 |
| CI Status | Both `test-sqlite` (42s) and `test-pgsql` (1m25s) passed |
| Test Failures | 0 |
| Test Errors | 0 |
| Changed Files (production) | 5 (GeminiPromptBuilder, AppServiceProvider, config/services.php, phpunit.xml, migration) |
| Changed Files (test) | 1 (GeminiPromptBuilderTest) |
| Total Lines Changed | ~290–340 (85 prod + 185 tests + 10 config + 30 migration) |
| Commits on Feature Branch | 8 (all integrated into main via merge commit `cc51c3e`) |

---

## Files Delivered

### Production Code
- `app/Services/Gemini/GeminiPromptBuilder.php` — Added `?DescartadosAnalisisService`, `int $negativeExamplesLimit`, `?Collection $cachedExamples`; refactored `buildEjemplosNegativos()` into router; extracted `buildHardcodedExamples()`, `getCachedExamples()`, `formatDynamicExamples()`.
- `app/Providers/AppServiceProvider.php` — Updated singleton closure to inject `DescartadosAnalisisService` and limit config.
- `config/services.php` — Added `gemini.negative_examples_enabled` (default `env('GEMINI_NEGATIVE_EXAMPLES_ENABLED', true)`) and `gemini.negative_examples_limit` (default 5).
- `database/migrations/2026_05_15_200000_add_descartado_confianza_idx_to_resultados_scraping.php` — Added composite index `(descartado, gemini_confianza DESC)` for query optimization.

### Test Code
- `tests/Unit/Gemini/GeminiPromptBuilderTest.php` — 13 new tests + 32 pre-existing (all green).
- `phpunit.xml` — Added `<env name="GEMINI_NEGATIVE_EXAMPLES_ENABLED" value="false"/>` to keep existing tests isolated.

### Specification
- `openspec/specs/gemini-filtro/spec.md` — Canonical spec now contains REQ-1 through REQ-10 (was REQ-1–4, added REQ-5–10 by this SDD).

---

## Operations Checklist — Post-Deploy

1. **Code Integration**: `git pull` on VPS to fetch merge commit `cc51c3e`.
2. **Migrations**: `php artisan migrate --force` to apply composite index migration.
3. **Queue Restart**: `php artisan queue:restart` to ensure workers pick up new `AppServiceProvider` singleton binding.
4. **Verify Logs**: Check Horizon / storage/logs for `gemini.negative_examples.injected` count entries on first analyzed articles.
5. **Monitor Precision**: Track 87.5% descarte baseline in bucket 85–100; re-measure +14 days post-deploy to measure calibration improvement.
6. **Rollback (fast)**: If needed, set `GEMINI_NEGATIVE_EXAMPLES_ENABLED=false` in `.env`, run `php artisan queue:restart`, and monitor logs. No code redeploy required.

---

## Known Limitations & Deferred Suggestions

5 non-blocking suggestions from verify phase carried forward:

1. **S1**: Add explicit `Log::shouldNotHaveReceived` assertion in test for REQ-8 hardcoded fallback path (cosmetic safety net).
2. **S2**: Handle null `gemini_motivo` with fallback e.g. `?? 'Sin motivo'` in `formatDynamicExamples()` (defensive, no current null examples in data).
3. **S3**: Commit subject `2bd1d4d` uses past tense ("was") which is slightly misleading for a feature commit (bisect-only cosmetic).
4. **S4**: Add dedicated regression test for commit `7e42909` to prevent accidental reversion of singleton binding logic (nice-to-have).
5. **S5**: Line 252 (`formatDynamicExamples()`) is the natural place to address S2 if null motivo ever appears.

None block production use. All are suggestions for future hygiene improvements.

---

## Lessons for Next SDDs

1. **Interface Extraction Discovery**: When designing services, discover if they're registered as singletons early (design phase). Document the decision and mitigation path explicitly.
2. **JSON Encoding for Prompt Building**: Use `json_encode()` with `JSON_UNESCAPED_UNICODE` instead of `sprintf` or `addslashes` for safe prompt assembly. Preserves Unicode, escapes quotes AND backslashes structurally.
3. **Singleton Binding Considerations**: Acceptable when data changes slowly (e.g. handful of descartados/day) and staleness is bounded (worker lifetime < 24h). Always document the assumption and provide a 1-line mitigation path.
4. **Feature Flag Pattern**: `env` flag → `config/` key → injectable parameter. Fast rollback without deploy. Pair with log instrumentation to verify flag is respected.
5. **Cache Invalidation on Singleton**: Per-instance property cache on a long-lived singleton is safe if the data is semi-static. Monitor staleness metrics in production.

---

## Related Future Work / Unblocks

1. **A/B Measurement Framework** (separate SDD): Measure impact of dynamic examples on precision at each confidence bucket. Requires baseline tracking (87.5% now) + re-measure +14d post-deploy.
2. **Per-Category Negative Examples** (separate SDD): Extend to inject category-specific examples (e.g. political, economic, sports) from `descartados` filtered by `gemini_categoria`. Requires expanding `DescartadosAnalisisService::getNegativeExamples()` signature.
3. **Monitoring Dashboard** (separate SDD): Real-time view of injected example counts, staleness metrics, and precision impact. Built on logs from production workers.
4. **Positive Example Injection** (separate SDD): Inject confirmed PEP+ articles as positive few-shot examples (lower priority, data validation required).

---

## Engram Artifacts

All phase artifacts recorded in Engram with observation IDs for traceability:
- **Proposal**: obs #994 — `sdd/gemini-negative-examples-prompt/proposal`
- **Spec (delta)**: obs #995 — `sdd/gemini-negative-examples-prompt/spec`
- **Design**: obs #996 — `sdd/gemini-negative-examples-prompt/design` + **Design Discovery #997** (singleton pattern)
- **Tasks**: obs #998 — `sdd/gemini-negative-examples-prompt/tasks`
- **Verify Report (re-verified APPROVED)**: obs #1001 — `sdd/gemini-negative-examples-prompt/verify-report`
- **Discovery that motivated this SDD**: obs #993 — `discovery/gemini-mal-calibrado-85-bucket` (87.5% descarte rate in high-confidence bucket)

---

## Canonical Spec Sync

**Base canonical capability** (from `gemini-confidence-persistence` SDD, archived 2026-05-15):
- `openspec/specs/gemini-filtro/spec.md` — REQ-1 through REQ-4 (DTO aggregation, persistence, backfill, cross-driver portability)

**This SDD's delta merged** (2026-05-15 archive):
- REQ-5: Dynamic Negative Examples Injection
- REQ-6: Per-Instance Example Cache
- REQ-7: Feature Flag Kill Switch
- REQ-8: Injection Count Logging
- REQ-9: Format Compatibility
- REQ-10: Backward Compatibility

**Result**: `openspec/specs/gemini-filtro/spec.md` now authoritative for all 10 requirements. Active changes folder for this SDD removed.

---

## Archive Timestamp

- **Archived**: 2026-05-15 (same day as merge to `main`)
- **Archive Path**: `openspec/archive/2026-05-15-gemini-negative-examples-prompt/`
- **Contents**:
  - `proposal.md` — Initial problem statement and scope
  - `explore.md` — Exploration notes (optional)
  - `specs/gemini-filtro/spec.md` — Delta spec (6 requirements)
  - `design.md` — Technical approach and DI discovery
  - `tasks.md` — Implementation breakdown (5 phases)
  - `verify-report.md` — Final verification (APPROVED, re-verified for quote-escaping fix)
  - `archive-report.md` — This document

Cycle complete. SDD is ready for production deployment.
