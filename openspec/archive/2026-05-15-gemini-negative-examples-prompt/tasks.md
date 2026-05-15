# Tasks: Inject Real Negative Examples into Gemini Filter Prompt

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~290–340 (≈85 prod + 185 tests + 10 config + 30 migration) |
| Files touched | 6 (`GeminiPromptBuilder`, `AppServiceProvider`, `config/services.php`, `phpunit.xml`, `GeminiPromptBuilderTest`, 1 migration) |
| Work units (commit-sized) | 5 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Delivery strategy | ask-on-risk |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: stacked-to-main
400-line budget risk: Low

> Single PR is safe. All units fit comfortably inside the 400-line budget.

---

## Phase 0 — Pre-flight: DB Index

- [x] 0.1 [PROD] Verify: no composite index on `resultados_scraping (descartado, gemini_confianza)` exists in migrations — CONFIRMED MISSING (only separate single-col indexes).
- [x] 0.2 [PROD] Create `database/migrations/2026_05_15_200000_add_descartado_confianza_idx_to_resultados_scraping.php`. Use raw `DB::statement()` with driver check: `CONCURRENTLY` for pgsql, plain `CREATE INDEX` for sqlite. Down drops it.
- [x] 0.3 [TEST] Run `php artisan migrate --env=testing` — migration must execute without error on SQLite CI driver.
- **Commit**: `chore(db): add composite index descartado+gemini_confianza on resultados_scraping` ✅

---

## Phase 1 — Config + Test Env

- [x] 1.1 [PROD] Add to `config/services.php` under `gemini` key: `negative_examples_enabled` (default `env('GEMINI_NEGATIVE_EXAMPLES_ENABLED', true)`) and `negative_examples_limit` (default `env('GEMINI_NEGATIVE_EXAMPLES_LIMIT', 5)`).
- [x] 1.2 [TEST-ENV] Add `<env name="GEMINI_NEGATIVE_EXAMPLES_ENABLED" value="false"/>` to `phpunit.xml` `<php>` section. Ensures all 35 existing tests stay in hardcoded-fallback mode without modification.
- [x] 1.3 No behavior tests needed — config keys are read-only values with no side effects.
- **Commit**: `chore(gemini): add negative_examples config keys + test env disable flag` ✅

---

## Phase 2 — Constructor Params + AppServiceProvider (backward-compat)

- [x] 2.1 [TEST] Run existing 35 `GeminiPromptBuilderTest` tests BEFORE any production changes — all must be green (baseline).
- [x] 2.2 [PROD] In `GeminiPromptBuilder.php`: add `?NegativeExamplesProvider $negativeExamplesService = null` and `int $negativeExamplesLimit = 5` to constructor. Add `private ?Collection $cachedExamples = null` property. Extracted `NegativeExamplesProvider` interface (in `app/Services/Contracts/`) since `DescartadosAnalisisService` is `final`.
- [x] 2.3 [PROD] In `AppServiceProvider::register()`: update the singleton closure to inject `negativeExamplesService: $app->make(NegativeExamplesProvider::class)` and `negativeExamplesLimit: (int) config('services.gemini.negative_examples_limit', 5)`. Bind `NegativeExamplesProvider → DescartadosAnalisisService` as singleton.
- [x] 2.4 [TEST] Run existing 35 tests again — all MUST STILL PASS (ctor defaults keep backward compat). ✅ 35/35
- **Commit**: (combined with Phase 3) `test+feat(gemini): inject dynamic negative examples in prompt builder` ✅

---

## Phase 3 — Router + Format Helpers (strict TDD: RED → GREEN)

- [x] 3.1 [TEST-RED] `test_construye_ejemplos_hardcodeados_cuando_servicio_es_null` — null service → hardcoded `[NEG]` blocks. Must fail before implementation.
- [x] 3.2 [TEST-RED] `test_construye_ejemplos_dinamicos_cuando_flag_habilitado` — service returns 5 articles, flag=true → 5 `[NEG-OP]` entries, no `[NEG]`.
- [x] 3.3 [TEST-RED] `test_servicio_retorna_vacio_usa_hardcodeados` — service returns empty Collection, flag=true → hardcoded fallback.
- [x] 3.4 [TEST-RED] `test_flag_deshabilitado_usa_hardcodeados` — service injected + 5 results, flag=false → hardcoded fallback.
- [x] 3.5 [TEST-RED] `test_formato_neg_op_es_correcto` — single article, verify exact string: `[NEG-OP] "titulo" → {"personas":[],"motivo_general":"motivo. Confianza original: X."}`.
- [x] 3.6 [TEST-RED] `test_respeta_limite_de_ejemplos_negativos` — service returns 10, limit=3 → exactly 3 `[NEG-OP]` entries.
- [x] 3.7 [TEST-RED] `test_caracteres_especiales_en_titulo_se_preservan` — título with accents/commas → verbatim in output, no escaping.
- [x] 3.8 [PROD] Implement in `GeminiPromptBuilder.php`: refactor `buildEjemplosNegativos()` as router. Extract `buildHardcodedExamples()` (existing logic). Add `getCachedExamples(): Collection` and `formatDynamicExamples(Collection $examples): string`.
- [x] 3.9 [TEST-GREEN] Run all `GeminiPromptBuilderTest` — MUST be 35 + 7 = **42 green**. ✅ 42/42
- **Commit**: `test+feat(gemini): inject dynamic negative examples in prompt builder` ✅

---

## Phase 4 — Cache Behavior + Logging (strict TDD: RED → GREEN)

- [x] 4.1 [TEST-RED] `test_cache_reusado_en_multiples_llamadas_misma_instancia` — mock expects `getNegativeExamples()` called exactly once, call `filtroPEP()` twice on same instance.
- [x] 4.2 [TEST-RED] `test_nueva_instancia_consulta_db_de_nuevo` — two separate `GeminiPromptBuilder` instances, each calls once → `getNegativeExamples()` called twice total.
- [x] 4.3 [TEST-RED] `test_loguea_cuenta_cuando_inyecta_dinamicos` — use `Log::spy()`, verify `Log::info('gemini.negative_examples.injected', ['count' => 5])` called once; no log for fallback path.
- [x] 4.4 [PROD] Verified cache (`$this->cachedExamples ??= ...`) and `Log::info(...)` paths inside `getCachedExamples()` and the router — already implemented correctly in Phase 3.
- [x] 4.5 [TEST-GREEN] Run all `GeminiPromptBuilderTest` — MUST be **45 green** (35 + 10). ✅ 45/45
- **Commit**: `test+feat(gemini): cache negative examples per-instance + log injection count` ✅

---

## Phase 5 — Final Verification + SDD Cleanup

- [x] 5.1 Run full test suite: `php -d memory_limit=512M vendor/bin/phpunit` — 924 tests, 0 failures, 0 errors (9 skipped, 1 incomplete — pre-existing). ✅
- [x] 5.2 Lint: confirmed `declare(strict_types=1)` at top of every touched PHP file. ✅
- [x] 5.3 Scan: no `dd()`, `dump()`, or `var_dump()` in touched files. ✅
- [x] 5.4 Updated this `tasks.md` checkboxes. ✅
- **Commit**: `chore(sdd): mark gemini-negative-examples-prompt tasks complete` ✅

---

## Deviation Notes

- **Interface extracted**: `DescartadosAnalisisService` is `final` — PHPUnit's `createMock()` cannot double it. Extracted `App\Services\Contracts\NegativeExamplesProvider` interface and made `DescartadosAnalisisService` implement it. `GeminiPromptBuilder` now depends on the interface, not the concrete class. This is cleaner architecture (DIP) and was not anticipated in tasks.md.
- **WU2+WU3 merged**: Pre-commit hook (Gentleman Guardian Angel) rejected WU2 alone as "dead code" (ctor params unused). WU2 and WU3 were combined into a single commit `test+feat(gemini): inject dynamic negative examples in prompt builder`.
- **Fix commit added**: `fix(gemini): guard config() call behind service null-check` — `buildEjemplosNegativos()` called `config()` unconditionally, breaking `PromptReglasTest` which uses pure `PHPUnit\Framework\TestCase`. Moved config call inside `isNegativeExamplesFlagEnabled()` helper with null-safe guard.

---

## Dependency Order

```
Phase 0 (migration) → Phase 1 (config+env) → Phase 2+3 (ctor+DI+router TDD) → Phase 4 (cache+log TDD) → Phase 5 (final verify)
```

Each phase is independently verifiable. Phase 3 and 4 follow strict TDD: write RED tests first, then implement GREEN, then verify all pass before moving on.
