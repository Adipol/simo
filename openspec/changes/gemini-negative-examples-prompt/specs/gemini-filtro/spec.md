# Delta for gemini-filtro

**Change**: `gemini-negative-examples-prompt`
**Date**: 2026-05-15
**Extends**: `openspec/specs/gemini-filtro/spec.md` (REQ-1 through REQ-4)

---

## ADDED Requirements

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

- A/B precision measurement framework
- Per-keyword tailoring of negative examples
- Pre-deploy precision baseline beyond 87.5% bucket tracking
- Multi-category prompting
- Dashboard for tracking injection impact
- Modifying `DescartadosAnalisisService::getNegativeExamples()` signature
- Positive (PEP+) example injection
