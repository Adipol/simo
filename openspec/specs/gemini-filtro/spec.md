# gemini-filtro Specification

**Capability**: `gemini-filtro`
**Added by**: `gemini-confidence-persistence` (2026-05-15)

## Purpose

Defines the persistence contract for the Gemini PEP/OPI filter pipeline. Every article analyzed by `GeminiFiltroService` MUST persist an aggregated `gemini_confianza` value derived from per-persona confidence scores. Articles rejected before Gemini analysis (pre-filter path) MUST leave the field NULL.

---

## Requirements

### Requirement: DTO Confidence Aggregation

`FiltroResultadoDTO` MUST expose a `maxConfianza(): ?int` method that returns the maximum `confianza` value across all `PersonaDetectadaDTO` personas, or `NULL` when the personas collection is empty or all confianza values are NULL.

#### Scenario: Single persona detected

- GIVEN a `FiltroResultadoDTO` with exactly one persona having `confianza = 85`
- WHEN `maxConfianza()` is called
- THEN it returns `85`

#### Scenario: Multiple personas — returns MAX

- GIVEN a `FiltroResultadoDTO` with personas having `confianza` values `[60, 90, 75]`
- WHEN `maxConfianza()` is called
- THEN it returns `90`

#### Scenario: Zero personas — returns NULL

- GIVEN a `FiltroResultadoDTO` with an empty personas array
- WHEN `maxConfianza()` is called
- THEN it returns `NULL`

#### Scenario: All personas have NULL confianza — returns NULL

- GIVEN a `FiltroResultadoDTO` with personas where all `confianza` values are `NULL`
- WHEN `maxConfianza()` is called
- THEN it returns `NULL`

#### Scenario: Mixed NULL and valued confianza — returns MAX of non-null

- GIVEN a `FiltroResultadoDTO` with personas having `confianza` values `[NULL, 70, NULL, 55]`
- WHEN `maxConfianza()` is called
- THEN it returns `70`

---

### Requirement: Persistence of gemini_confianza on Analysis

`GeminiFiltroService::persistirResultado()` MUST persist `gemini_confianza` on the `resultados_scraping` parent record using `$dto->maxConfianza()` every time an article completes Gemini analysis.

#### Scenario: Single persona analyzed — confianza persisted

- GIVEN an article analyzed by Gemini with one persona (`confianza = 80`)
- WHEN `persistirResultado()` executes
- THEN `resultados_scraping.gemini_confianza` is `80`
- AND `gemini_analyzed` is `true`

#### Scenario: Multiple personas analyzed — MAX persisted

- GIVEN an article analyzed by Gemini with personas having `confianza` values `[50, 95]`
- WHEN `persistirResultado()` executes
- THEN `resultados_scraping.gemini_confianza` is `95`

#### Scenario: Zero personas — NULL persisted

- GIVEN an article analyzed by Gemini where Gemini returns zero detected personas
- WHEN `persistirResultado()` executes
- THEN `resultados_scraping.gemini_confianza` is `NULL`

#### Scenario: Pre-filter rejection — field untouched

- GIVEN an article rejected by `PreFiltroService` before Gemini is called
- WHEN processing completes
- THEN `resultados_scraping.gemini_confianza` remains `NULL`
- AND `resultados_scraping.gemini_analyzed` remains `false`

---

### Requirement: Backfill Command

Artisan command `simo:backfill-gemini-confianza` MUST retroactively populate `gemini_confianza` for historical rows where `gemini_analyzed = true AND gemini_confianza IS NULL`, using `MAX(resultado_personas.confianza)` via Eloquent. The command MUST be idempotent and MUST support a `--dry-run` flag. It MUST use `chunkById(100)` for memory safety.

#### Scenario: Analyzed row with personas — updated

- GIVEN a `resultados_scraping` row with `gemini_analyzed = true`, `gemini_confianza = NULL`
- AND at least one related `resultado_personas` row with `confianza = 88`
- WHEN `simo:backfill-gemini-confianza` runs
- THEN `gemini_confianza` is set to `88`

#### Scenario: Analyzed row with no personas — skipped

- GIVEN a `resultados_scraping` row with `gemini_analyzed = true`, `gemini_confianza = NULL`
- AND zero related `resultado_personas` rows
- WHEN `simo:backfill-gemini-confianza` runs
- THEN `gemini_confianza` remains `NULL`

#### Scenario: Already-populated row — skipped (idempotency)

- GIVEN a `resultados_scraping` row with `gemini_analyzed = true`, `gemini_confianza = 72`
- WHEN `simo:backfill-gemini-confianza` runs
- THEN `gemini_confianza` remains `72` (not overwritten)

#### Scenario: Unanalyzed row — not touched

- GIVEN a `resultados_scraping` row with `gemini_analyzed = false`
- WHEN `simo:backfill-gemini-confianza` runs
- THEN the row is not modified

#### Scenario: Dry-run reports without writing

- GIVEN rows eligible for update
- WHEN `simo:backfill-gemini-confianza --dry-run` runs
- THEN the command outputs counts (scanned, would-update, skipped-no-personas, skipped-already-set)
- AND no rows are mutated in the database

#### Scenario: Reports four counts on normal run

- GIVEN a mixed dataset of eligible, ineligible, already-populated, and unanalyzed rows
- WHEN `simo:backfill-gemini-confianza` runs
- THEN the command outputs: total scanned, total updated, total skipped (no personas), total skipped (already populated)

---

### Requirement: Cross-Driver Portability

All Eloquent queries introduced by this change MUST produce identical results on SQLite and Postgres. No raw SQL aggregations are permitted; Eloquent relationship-based aggregation MUST be used.

#### Scenario: Backfill on Postgres matches SQLite

- GIVEN an identical fixture dataset loaded on both SQLite and Postgres
- WHEN `simo:backfill-gemini-confianza` runs on each
- THEN the count of updated rows is identical on both drivers

---

### Requirement: REQ-5 Dynamic Negative Examples Injection

`GeminiPromptBuilder::buildEjemplosNegativos()` MUST inject up to N (default 5, configurable) real high-confidence operator-rejected articles as `[NEG-OP]` negative examples when a `DescartadosAnalisisService` is injected AND the feature flag `services.gemini.negative_examples_enabled` is `true`. When the service is null, the result is empty, or the flag is `false`, the method MUST fall back to the hardcoded fictional examples.

#### Scenario: Service injected, flag enabled, N examples returned

- GIVEN `GeminiPromptBuilder` constructed with a `DescartadosAnalisisService` returning 5 articles
- AND `services.gemini.negative_examples_enabled = true`
- WHEN `buildEjemplosNegativos()` is called
- THEN the returned string contains exactly those 5 articles formatted as `[NEG-OP]` entries
- AND no hardcoded fictional examples appear

#### Scenario: Service injected but returns 0 examples — graceful fallback

- GIVEN `GeminiPromptBuilder` constructed with a `DescartadosAnalisisService` returning an empty Collection
- AND the feature flag is `true`
- WHEN `buildEjemplosNegativos()` is called
- THEN the hardcoded fictional examples are returned

#### Scenario: Service null (not injected) — hardcoded fallback

- GIVEN `GeminiPromptBuilder` constructed without a `DescartadosAnalisisService` (null)
- WHEN `buildEjemplosNegativos()` is called
- THEN the hardcoded fictional examples are returned

#### Scenario: Flag disabled — hardcoded fallback even if service injected

- GIVEN `GeminiPromptBuilder` constructed with a `DescartadosAnalisisService` returning 5 articles
- AND `services.gemini.negative_examples_enabled = false`
- WHEN `buildEjemplosNegativos()` is called
- THEN the hardcoded fictional examples are returned

#### Scenario: Service returns fewer than limit — no padding

- GIVEN `GeminiPromptBuilder` with limit = 5 and service returns 3 articles
- AND the feature flag is `true`
- WHEN `buildEjemplosNegativos()` is called
- THEN the returned string contains exactly 3 `[NEG-OP]` entries

---

### Requirement: REQ-6 Per-Instance Example Cache

`GeminiPromptBuilder` MUST cache the fetched negative-example Collection in a private `?Collection $cachedExamples` property, ensuring exactly one DB call per instance lifetime regardless of how many times `buildEjemplosNegativos()` is invoked.

#### Scenario: First call fetches from service

- GIVEN a fresh `GeminiPromptBuilder` instance with a `DescartadosAnalisisService`
- WHEN `buildEjemplosNegativos()` is called for the first time
- THEN `DescartadosAnalisisService::getNegativeExamples()` is called exactly once

#### Scenario: Second call on same instance uses cache

- GIVEN a `GeminiPromptBuilder` instance where `buildEjemplosNegativos()` was already called once
- WHEN `buildEjemplosNegativos()` is called a second time
- THEN `DescartadosAnalisisService::getNegativeExamples()` is NOT called again

#### Scenario: New instance fetches again

- GIVEN two separate `GeminiPromptBuilder` instances with the same service
- WHEN each calls `buildEjemplosNegativos()` for the first time
- THEN `getNegativeExamples()` is called once per instance (twice total)

---

### Requirement: REQ-7 Feature Flag Kill Switch

The dynamic injection behavior MUST be controlled by env `GEMINI_NEGATIVE_EXAMPLES_ENABLED` resolved via `config/services.php` as `services.gemini.negative_examples_enabled`. The config key MUST default to `true` when missing.

#### Scenario: Config true → injection active

- GIVEN `services.gemini.negative_examples_enabled = true` and service injected with examples
- WHEN `buildEjemplosNegativos()` is called
- THEN dynamic examples are used

#### Scenario: Config false → hardcoded fallback

- GIVEN `services.gemini.negative_examples_enabled = false`
- WHEN `buildEjemplosNegativos()` is called
- THEN hardcoded fictional examples are returned regardless of service state

#### Scenario: Config missing → defaults to true

- GIVEN `GEMINI_NEGATIVE_EXAMPLES_ENABLED` is not set in env
- WHEN `services.gemini.negative_examples_enabled` is resolved
- THEN it evaluates to `true`

#### Scenario: Test env override false → existing tests unaffected

- GIVEN tests that set `services.gemini.negative_examples_enabled = false` or pass `null` as service
- WHEN the existing 17+ `GeminiPromptBuilderTest` tests run
- THEN all pass without modification and see no dynamic injection

---

### Requirement: REQ-8 Injection Count Logging

When dynamic negative examples are injected (non-empty result used), the prompt builder MUST call `Log::info('gemini.negative_examples.injected', ['count' => N])` with the count of examples included. No example content SHALL be logged (PII/content risk). When falling back to hardcoded examples, no log entry SHALL be emitted via this channel.

#### Scenario: 5 dynamic examples injected → log count 5

- GIVEN service returns 5 examples, flag enabled
- WHEN `buildEjemplosNegativos()` is called
- THEN `Log::info('gemini.negative_examples.injected', ['count' => 5])` is recorded

#### Scenario: Fallback to hardcoded → no log entry

- GIVEN service is null OR flag disabled OR service returns empty
- WHEN `buildEjemplosNegativos()` is called
- THEN no `gemini.negative_examples.injected` log entry is recorded

---

### Requirement: REQ-9 Format Compatibility

Dynamic `[NEG-OP]` entries MUST use exactly the same schema as the existing hardcoded ones:

```
[NEG-OP] "<titulo>" → {"personas":[],"motivo_general":"<motivo>. Confianza original: <X>."}
```

No deviation in key names, structure, or punctuation is permitted.

#### Scenario: Well-formed entry from descartado record

- GIVEN a `ResultadoScraping` with `titulo = "Renuncia del DT del club Bolívar"`, `gemini_motivo = "Deportivo"`, `gemini_confianza = 95`
- WHEN formatted as a `[NEG-OP]` entry
- THEN the output is exactly:
  `[NEG-OP] "Renuncia del DT del club Bolívar" → {"personas":[],"motivo_general":"Deportivo. Confianza original: 95."}`

#### Scenario: Special characters preserved

- GIVEN a `titulo` containing accented characters (e.g. `"Álvaro Pérez dimite"`) and a `gemini_motivo` with commas
- WHEN formatted as a `[NEG-OP]` entry
- THEN accents and punctuation appear verbatim without escaping or corruption

---

### Requirement: REQ-10 Backward Compatibility

All existing `GeminiPromptBuilderTest` tests (17+) MUST pass without modification. The constructor MUST accept being called without arguments or with only `?PepCatalogService` as before.

#### Scenario: Constructor with no args → hardcoded fallback

- GIVEN `new GeminiPromptBuilder()` (no arguments)
- WHEN `buildEjemplosNegativos()` is called
- THEN the hardcoded fictional examples are returned and no exception is thrown

#### Scenario: Existing PepCatalogService injection pattern unaffected

- GIVEN `new GeminiPromptBuilder(catalog: $pepService)` (existing test pattern)
- WHEN the component assembles a prompt
- THEN behavior is identical to before this change (orthogonal injection)

---

## Out of Scope

- `DescartadosAnalisisService` — unchanged; already assumes `gemini_confianza` is populated.
- `GeminiAnalisisService` — writes to `cambios`, separate concern.
- Dashboard or CLI presentation — already consumes the column.
- Schema migrations — column `gemini_confianza` already exists.
- A/B precision measurement framework
- Per-keyword tailoring of negative examples
- Pre-deploy precision baseline beyond 87.5% bucket tracking
- Multi-category prompting
- Dashboard for tracking injection impact
- Modifying `DescartadosAnalisisService::getNegativeExamples()` signature
- Positive (PEP+) example injection
