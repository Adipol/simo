# Exploration: gemini-negative-examples-prompt

**Date**: 2026-05-15
**Phase**: explore
**Status**: success

---

## Current State

### Prompt Builder Architecture

`GeminiPromptBuilder` (430 LOC, `app/Services/Gemini/GeminiPromptBuilder.php`) is a pure service class that produces prompt strings. It has two public prompt methods:

1. **`filtroPEP(string $texto, string $pais, string $categoria): string`** — classifies a scraped article as PEP/OPI. Used by `GeminiFiltroService`. This is the TARGET for this SDD.
2. **`analisisCambio(...)` / `analisisCambioMultimodal(...)`** — analyzes government source diffs. Out of scope.

The `filtroPEP` prompt is assembled by four private builder methods (all returning static string literals):
- `buildReglasClasificacion()` — classification rules block
- `buildEjemplosNegativos()` — **HARDCODED** negative examples (3 handwritten fictional NEG + 1 PEP+, ~600 chars)
- `buildContextoCategoria(string $categoria)` — category-specific context hint
- Dynamic PEP catalog section (from `PepCatalogService`) or generic fallback

The existing `buildEjemplosNegativos()` produces static fictional examples. **The seam for dynamic examples exists: it's the `{$ejemplosNegativos}` interpolation in both `buildDynamicPrompt()` and `buildGenericPrompt()`.**

### Current Constructor

```php
public function __construct(
    private readonly ?PepCatalogService $catalog = null,
) {}
```

Constructor already uses nullable optional injection. Adding `DescartadosAnalisisService` follows the same pattern.

### Negative Examples Seam (`DescartadosAnalisisService::getNegativeExamples`)

```php
public function getNegativeExamples(int $limit = 10): Collection
{
    return ResultadoScraping::where('descartado', true)
        ->where('gemini_confianza', '>=', 70)
        ->orderBy('gemini_confianza', 'desc')
        ->limit($limit)
        ->get();
}
```

Key properties of the seam:
- **NOT cached** (by design, per archive decision #10: "not consumed by T1 or T2 today")
- Returns `Collection<ResultadoScraping>` — full Eloquent models
- Filter: `descartado=true AND gemini_confianza >= 70` ordered by confidence DESC
- Parameter: `$limit = 10` (default)
- Relevant columns for prompt inclusion: `titulo` (article title), `contexto` (article text snippet), `gemini_motivo` (why Gemini classified it), `keyword`, `pais`, `categoria`

**Edge cases confirmed**:
- Empty result when no high-confidence descartados exist → method returns empty Collection
- Fewer than `$limit` results → returns what exists
- `gemini_confianza` may be NULL on some rows → filtered OUT by `>= 70` constraint
- No recency filter — returns globally highest-confidence descartados, not most recent

### How GeminiFiltroService Wires the Builder

```php
class GeminiFiltroService {
    public function __construct(
        private GeminiService $gemini,
        private GeminiPromptBuilder $builder,
        private PreFiltroService $preFiltro = new PreFiltroService,
        private NombreNormalizadorInterface $normalizador = new NombreNormalizador,
    ) {}
```

`GeminiFiltroService` receives `GeminiPromptBuilder` via constructor injection (resolved by Laravel IoC). The service resolves `$this->builder->filtroPEP(...)` on each article processing call.

### IoC Container Resolution

Laravel's service container resolves `GeminiPromptBuilder` automatically. `DescartadosAnalisisService` is also container-resolvable (it takes `CacheRepository` via constructor). Both can be auto-resolved. No manual binding in `AppServiceProvider` found for either — they rely on auto-resolution.

### Token Budget Reality Check

Current `filtroPEP` prompt (generic) approximate token count:
- Static system instructions: ~300 tokens
- PEP catalog block (varies by country): ~200–800 tokens
- Reglas de clasificacion: ~130 tokens
- Ejemplos negativos (static hardcoded): ~200 tokens
- Contexto categoria: ~50 tokens
- Article text (`$texto`): variable, typically 500–2000 chars (~125–500 tokens)
- **Total current range**: ~1000–1800 tokens per call

Adding 5 dynamic negative examples at ~60 words each = ~400 tokens additional.
Adding 10 examples = ~800 tokens additional.
Gemini Flash (gemini-1.5-flash) has a 1M token context window — no hard limit risk.
Cost impact: ~25–50% prompt token increase for 5–10 examples at current volumes (160 articles processed).

---

## Affected Areas

- `app/Services/Gemini/GeminiPromptBuilder.php` — **primary change target**: add `DescartadosAnalisisService` constructor param, modify `buildEjemplosNegativos()` to accept dynamic examples, add `buildDynamicNegativeExamples()` helper
- `app/Services/Gemini/GeminiFiltroService.php` — **no public API change required**: `GeminiPromptBuilder` resolves via IoC; if `GeminiPromptBuilder`'s constructor adds an optional param, Laravel auto-injects it without touching `GeminiFiltroService`
- `app/Services/DescartadosAnalisisService.php` — **consumed, NOT modified**: `getNegativeExamples()` seam is used read-only
- `tests/Unit/Gemini/GeminiPromptBuilderTest.php` — **new tests added** following TDD: test that dynamic negative examples appear in prompt, test empty-set fallback to static examples, test limit config, test format of dynamic block
- `tests/Unit/Gemini/GeminiPromptBuilderNegativeExamplesTest.php` (new file) OR extend existing test file
- `app/Providers/AppServiceProvider.php` — likely **no change**: auto-resolution handles it, but may need to verify if binding is needed

---

## Approaches

### Approach A: Optional Constructor Injection with Feature Flag (RECOMMENDED)

Add `DescartadosAnalisisService` as nullable optional constructor param to `GeminiPromptBuilder`, mirroring the existing `PepCatalogService` pattern. Add a `bool $negativeExamplesEnabled` flag (or config-driven).

```php
public function __construct(
    private readonly ?PepCatalogService $catalog = null,
    private readonly ?DescartadosAnalisisService $negativeExamplesService = null,
    private readonly int $negativeExamplesLimit = 5,
) {}
```

`buildEjemplosNegativos()` becomes:

```php
private function buildEjemplosNegativos(): string
{
    $static = $this->buildStaticNegativeExamples(); // existing hardcoded block

    if ($this->negativeExamplesService === null) {
        return $static;
    }

    $dynamic = $this->negativeExamplesService->getNegativeExamples($this->negativeExamplesLimit);

    if ($dynamic->isEmpty()) {
        return $static; // graceful fallback
    }

    return $static . "\n" . $this->formatDynamicExamples($dynamic);
}
```

- **Pros**: Mirrors existing PepCatalogService pattern (zero learning curve). Graceful fallback when no examples exist. Easily testable with null injection. No config() coupling needed — flag is constructor arg. IoC auto-resolves the new param in production; tests can pass null. No breaking changes to existing API.
- **Cons**: Prompt builder now has a DB dependency (indirect). Need to cache the `getNegativeExamples()` call at the caller level OR add caching inside `buildEjemplosNegativos()` (since it's called per article). Requires binding or config for `negativeExamplesLimit`.
- **Effort**: Low

### Approach B: Cache `getNegativeExamples()` in DescartadosAnalisisService

Instead of modifying `GeminiPromptBuilder`, add a `getCachedNegativeExamples()` method to `DescartadosAnalisisService` with 5-min TTL (matching the existing `CACHE_TTL = 300`). Then wire via Approach A.

- **Pros**: DB not hit per article (critical for batch processing 160 articles). TTL=300s aligns with dashboard refresh. Cache key follows existing CACHED_KEY_SPECS pattern.
- **Cons**: Modifying `DescartadosAnalisisService` business logic vs just consuming the seam. Archive decision #10 says "not consumed by T1 or T2" — this is now T3 consuming it, so it's appropriate. But the constraint says "Do NOT modify DescartadosAnalisisService business logic."
- **Effort**: Low-Medium

### Approach C: Cache inside GeminiPromptBuilder

Add a static property or `Cache::remember()` call inside `buildEjemplosNegativos()` to cache the DB call.

- **Pros**: Keeps DescartadosAnalisisService untouched. Self-contained.
- **Cons**: Cache dependency in a "pure" prompt builder is a code smell. Harder to test deterministically. Violates single-responsibility for a builder class.
- **Effort**: Low

---

## Recommendation

**Approach A + lazy DB call protection strategy**: Use optional constructor injection (Approach A pattern). For the caching concern, note that `getNegativeExamples()` returns the same result for any batch run within a short window. The recommended implementation is:

1. Add `DescartadosAnalisisService` as nullable optional param to `GeminiPromptBuilder`
2. Add `int $negativeExamplesLimit = 5` as constructor param (configurable via `config('services.gemini.negative_examples_limit', 5)`)
3. Cache the examples collection **at the builder level** using a private `?Collection $cachedExamples = null` property — this is a per-request cache (not a TTL cache), valid for the lifetime of the builder instance. In batch scenarios (one `GeminiFiltroService` instance processes N articles), this means 1 DB call per batch, not 1 per article. This is the minimal-change, zero-cache-driver approach.
4. Keep the existing static examples as fallback (empty collection or null service)
5. Format dynamic examples as `[NEG-REAL] "titulo" → {json}` to distinguish them from hardcoded examples

**Config key** (new): `services.gemini.negative_examples_limit` (default: 5)
**Flag**: No explicit toggle flag needed — null service = disabled, non-null = enabled. In production, IoC resolves the service; in tests, pass null explicitly.

---

## Dynamic Example Format

Each dynamic negative example should appear as:

```
[NEG-OP] "Álvaro Pérez fue detenido durante una manifestación en el centro"
→ {"personas":[],"motivo_general":"Persona civil detenida en protesta. Operador marcó como no-PEP/OPI. Confianza original: 85."}
```

Fields to use from `ResultadoScraping`:
- `titulo` — article title (for the quoted text)
- `contexto` — article snippet (optional, truncated to 200 chars if too long)
- `gemini_motivo` — Gemini's own rejection reasoning (if available)
- `gemini_confianza` — include as "Confianza original: {N}" to signal these were borderline cases

Limit title to 150 chars to avoid prompt bloat.

---

## Out of Scope for This SDD

1. **A/B testing framework** — measuring before/after precision improvement. Would require tracking which prompt version was used per article. Separate SDD.
2. **UI to manage negative examples** — allowing operators to flag specific articles explicitly as "use this as a negative example." The current approach uses all high-confidence descartados implicitly.
3. **Multi-category prompting** — separate prompt per article category. Orthogonal concern.
4. **Modifying `getNegativeExamples()` signature** — the seam is consumed as-is. If recency filter or category filter is needed, that's a future SDD.
5. **Positive example injection** — symmetrically injecting verified PEP articles as positive examples. Could help, but scope creep.
6. **Metrics tracking** — adding a `gemini_negative_examples_count` column to `gemini_usage_log` to track how many examples were injected. Valuable but separate.

---

## Risks

| Risk | Severity | Mitigation |
|---|---|---|
| **DB call per article in batch** | High | Per-instance caching in GeminiPromptBuilder (`?Collection $cachedExamples`) — 1 DB call per batch run |
| **Token budget growth** | Low | Flash has 1M token window; 5 examples add ~400 tokens; total remains well under 5K |
| **Cost increase** | Medium | ~25-50% prompt token growth at current 160-article volume = negligible absolute cost; configurable limit |
| **Negative label quality** | High | `gemini_confianza >= 70` filter ensures only high-confidence descartados are used; however if operators mislabel relevantes as descartados, those become poisoned negatives. No current validation of operator labeling accuracy. Mitigation: start with limit=5 to minimize exposure |
| **Empty set at project start** | Low | Graceful fallback to static examples already planned in Approach A |
| **Precision regression** | Medium | No baseline exists to compare before/after. Risk: poorly-chosen negatives confuse Gemini. Mitigation: start with 5 high-confidence examples only |
| **Cross-driver query compatibility** | None | `getNegativeExamples()` uses simple Eloquent — no raw SQL, works on both SQLite and Postgres |
| **Breaking existing tests** | Low | Constructor param is optional (nullable default); existing tests use `new GeminiPromptBuilder` or `new GeminiPromptBuilder(null)` — both still work |
| **Cache pollution from wrong-labeled descartados** | Medium | Identical to label quality risk; mitigated by the `>= 70` confianza threshold |

---

## Ready for Proposal

Yes. Architecture is clear, seam is confirmed functional, approach is decided. The change is small and well-contained:
- **1 class modified**: `GeminiPromptBuilder` (add constructor param, modify `buildEjemplosNegativos`)
- **1 class consumed (read-only)**: `DescartadosAnalisisService::getNegativeExamples()`
- **1 new config key**: `services.gemini.negative_examples_limit`
- **New tests**: unit tests for `GeminiPromptBuilder` (TDD-first)
- **No DB migrations, no new routes, no Livewire changes**

Estimated effort: 2–3 hours implementation + 1 hour tests = single PR, well under 400-line budget.
