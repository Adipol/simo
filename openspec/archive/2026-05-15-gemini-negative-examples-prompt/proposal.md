# Proposal: Inject Real Negative Examples into Gemini Filter Prompt

## Intent

Production data (2026-05-15, 112 backfilled articles) shows Gemini is **uncalibrated and over-permissive**: ALL high-confidence outputs land in the 85–100 bucket, and humans descart **87.5%** of them. The current `filtroPEP` prompt uses 3 hardcoded fictional negative examples (~200 tokens). This change replaces them with up to 5 real, high-confidence operator-rejected articles fetched via the existing `DescartadosAnalisisService::getNegativeExamples()` seam (newly populated by archived PR #38). Goal: feedback-loop the filter so Gemini learns the actual rejection patterns operators see in production.

## Scope

### In Scope

- Add `?DescartadosAnalisisService` + `int $negativeExamplesLimit` (default 5) optional constructor params to `GeminiPromptBuilder`.
- Add per-instance `?Collection $cachedExamples` property → 1 DB call per batch.
- Modify `buildEjemplosNegativos()`: if service is null OR returns empty Collection → use existing hardcoded fallback; else format as `[NEG-OP] "<titulo>" → {"personas":[],"motivo_general":"<motivo>. Confianza original: <X>."}`.
- Add `services.gemini.negative_examples_limit` (default 5) and `services.gemini.negative_examples_enabled` (default `true`, `false` in tests) config keys.
- Log count-only on injection: `Log::info('gemini.negative_examples.injected', ['count' => N])`.
- TDD-first unit tests (`GeminiPromptBuilderTest`) + feature test asserting examples appear in real prompt.

### Out of Scope

- A/B precision-measurement framework (separate SDD).
- Per-keyword / per-categoría example selection.
- Positive (PEP+) example injection.
- Model swap from `gemini-2.5-flash`.
- Dashboard instrumentation of negative-example impact.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `gemini-filtro`: prompt assembly contract gains a requirement for dynamic negative-example injection with safe fallback and feature-flag gating.

## Approach

Approach A from exploration (#980): optional constructor injection mirroring the existing `?PepCatalogService` pattern. Laravel IoC auto-wires `DescartadosAnalisisService` in production; tests pass `null` explicitly. Per-instance Collection cache prevents N+1 across batch processing. Hardcoded examples remain as a baked-in fallback so the system never produces a degraded prompt. Env flag `GEMINI_NEGATIVE_EXAMPLES_ENABLED` provides a no-deploy kill switch.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/Gemini/GeminiPromptBuilder.php` | Modified | New constructor params, new private formatter, modified `buildEjemplosNegativos()` |
| `config/services.php` | Modified | Add `gemini.negative_examples_limit` + `gemini.negative_examples_enabled` |
| `app/Services/DescartadosAnalisisService.php` | Read-only consumer | No changes |
| `app/Services/Gemini/GeminiFiltroService.php` | Untouched | IoC auto-resolves new builder dependencies |
| `tests/Unit/Gemini/GeminiPromptBuilderTest.php` | Modified | New tests for dynamic-injection paths; existing 17+ tests unchanged (pass null) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Tokens +~400/call | High | Negligible (0.04% of 1M Flash window) |
| Mislabeled descartados poison examples | Medium | Filter `gemini_confianza >= 70`; limit 5 |
| Gemini still uncalibrated after change | Medium | Hypothesis-validation by design; cheap to revert via env flag |
| Operators see fewer PEP candidates post-deploy | Low | `GEMINI_NEGATIVE_EXAMPLES_ENABLED=false` kill switch |
| Stale cache in long-running worker | Low | Per-process invocation; not a daemon |
| No A/B baseline | High | Track 87.5% descarte at confianza ≥85 baseline; re-measure 1–2 weeks post-deploy |

## Rollback Plan

1. **Fast path**: set `GEMINI_NEGATIVE_EXAMPLES_ENABLED=false` in `.env` → restart workers. Builder reverts to hardcoded fallback. Zero deploy.
2. **Code path**: revert the merge commit. Constructor param is nullable, IoC tolerates absence; no DB rollback needed (no schema changes).

## Dependencies

- Archived SDD `gemini-confidence-persistence` (PR #38, 2026-05-15) — populates `resultados_scraping.gemini_confianza`. Without it, `getNegativeExamples()` returns empty and we silently fall back.

## Success Criteria

- [ ] Existing 17+ `GeminiPromptBuilderTest` tests pass without modification.
- [ ] New tests cover: null service, empty Collection, populated Collection, per-instance cache reuse, env-flag disabled.
- [ ] Manual prompt inspection confirms `[NEG-OP]` blocks present in production.
- [ ] `Log::info('gemini.negative_examples.injected', ['count' => N])` visible in production logs.
- [ ] Baseline tracked: 87.5% descarte at confianza ≥85 (pre-change). Re-measure after 14 days.
- [ ] No production cost regression beyond +0.5% per analysis call.
