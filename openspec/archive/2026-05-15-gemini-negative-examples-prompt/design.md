# Design: Inject Real Negative Examples into Gemini Filter Prompt

**Change**: `gemini-negative-examples-prompt` | **Date**: 2026-05-15 | **Phase**: design

## Technical Approach

Extend `GeminiPromptBuilder` with optional constructor injection of `?DescartadosAnalisisService` and an integer limit, mirroring the existing `?PepCatalogService` pattern. `buildEjemplosNegativos()` becomes a router: dynamic path when service is non-null AND `services.gemini.negative_examples_enabled = true` AND the service returns a non-empty Collection; hardcoded fallback in every other case. A per-instance `?Collection $cachedExamples` ensures one DB call per builder lifetime. No changes to `DescartadosAnalisisService` or `GeminiFiltroService`.

## Architecture Decisions

### Decision 1: DI binding — keep singleton, accept bounded staleness

**Finding (CRITICAL)**: `app/Providers/AppServiceProvider.php:26` registers `GeminiPromptBuilder` as `singleton()`. Per-instance cache → per-process cache. In long-lived workers (Horizon, Octane) the same Collection is reused across many jobs.

| Option | Tradeoff | Decision |
|---|---|---|
| A. Keep singleton + per-instance cache | Stale until worker restart (≈ minutes to hours). One extra DB query per worker, not per job. | ✅ Chosen |
| B. Convert to `bind()` (transient) | Fresh DB query per job. Safer staleness, +N queries/day. Touches AppServiceProvider. | Rejected — over-engineering for slow-changing data |
| C. Singleton + TTL refresh inside builder | Adds clock dependency, complicates testing, premature. | Rejected |

**Rationale**: Descartados ordered by confidence change slowly (operators descart a handful per day). A worker restart cycle (typically <24h via Horizon `--max-time`) bounds staleness acceptably. Tests are unaffected because PHPUnit unit tests construct `new GeminiPromptBuilder()` directly (bypassing the container) and queue is `sync` per `phpunit.xml`. **Documented for future**: if staleness becomes a problem, switch to option B in `AppServiceProvider`.

### Decision 2: Feature flag default = `true`, test override via PHPUnit env

| Option | Tradeoff | Decision |
|---|---|---|
| Default `true`, add `GEMINI_NEGATIVE_EXAMPLES_ENABLED=false` to `phpunit.xml` `<php>` block | One env line in test config, prod stays opt-out via env | ✅ Chosen |
| Default `false`, opt-in via prod `.env` | Safer rollout but every prod deploy needs env edit | Rejected — kill switch is the rollback story |

### Decision 3: Format — mirror hardcoded schema exactly

`[NEG-OP] "<titulo>" → {"personas":[],"motivo_general":"<motivo>. Confianza original: <X>."}` — chosen because Gemini's few-shot pattern matching is anchor-sensitive. Tag `[NEG-OP]` (vs. `[NEG]`) signals "operator-rejected" to allow A/B comparison in logs without breaking existing few-shot conditioning.

## Data Flow

    GeminiFiltroService::filtrar()
            │
            ▼
    GeminiPromptBuilder::filtroPEP()
            │
            ▼
    buildEjemplosNegativos()
            │
            ├─ flag disabled OR service null ──→ buildHardcodedExamples()
            │
            ├─ getCachedExamples()
            │       │
            │       ├─ first call ──→ DescartadosAnalisisService::getNegativeExamples($limit)
            │       │                          │
            │       │                          ▼
            │       │                  DB: resultados_scraping WHERE descartado=true AND confianza>=70
            │       │
            │       └─ subsequent calls ──→ $this->cachedExamples (no DB)
            │
            ├─ Collection empty ──→ buildHardcodedExamples()
            │
            └─ Collection non-empty ──→ Log::info('gemini.negative_examples.injected', count)
                                       └─→ formatDynamicExamples()

## File Changes

| File | Action | Description |
|---|---|---|
| `app/Services/Gemini/GeminiPromptBuilder.php` | Modify | Add 2 ctor params + `$cachedExamples`. Refactor `buildEjemplosNegativos()` into router; extract hardcoded text into `buildHardcodedExamples()`. Add `getCachedExamples()` and `formatDynamicExamples()`. |
| `app/Providers/AppServiceProvider.php` | Modify | Update singleton closure to inject `DescartadosAnalisisService` and `negative_examples_limit` config. |
| `config/services.php` | Modify | Add `gemini.negative_examples_enabled` and `gemini.negative_examples_limit` keys. |
| `phpunit.xml` | Modify | Add `<env name="GEMINI_NEGATIVE_EXAMPLES_ENABLED" value="false"/>` to keep existing tests untouched. |
| `tests/Unit/Gemini/GeminiPromptBuilderTest.php` | Modify | Add ~10 new test methods covering REQ-5..REQ-10 scenarios. Existing 32 tests untouched. |

## Interfaces / Contracts

```php
// GeminiPromptBuilder constructor
public function __construct(
    private readonly ?PepCatalogService $catalog = null,
    private readonly ?DescartadosAnalisisService $negativeExamplesService = null,
    private readonly int $negativeExamplesLimit = 5,
) {}

private ?Collection $cachedExamples = null;

// Router method (replaces current buildEjemplosNegativos)
private function buildEjemplosNegativos(): string
{
    $enabled = (bool) config('services.gemini.negative_examples_enabled', true);

    if ($enabled && $this->negativeExamplesService !== null) {
        $examples = $this->getCachedExamples();
        if ($examples->isNotEmpty()) {
            Log::info('gemini.negative_examples.injected', ['count' => $examples->count()]);
            return $this->formatDynamicExamples($examples);
        }
    }
    return $this->buildHardcodedExamples();
}

private function getCachedExamples(): Collection
{
    return $this->cachedExamples ??=
        $this->negativeExamplesService->getNegativeExamples($this->negativeExamplesLimit);
}

private function formatDynamicExamples(Collection $examples): string
{
    return $examples->map(function ($r): string {
        $titulo    = (string) $r->titulo;
        $motivo    = $r->gemini_motivo ?? 'Sin motivo';
        $confianza = (int) $r->gemini_confianza;
        return sprintf(
            '[NEG-OP] "%s" → {"personas":[],"motivo_general":"%s. Confianza original: %d."}',
            $titulo, $motivo, $confianza,
        );
    })->implode("\n");
}

private function buildHardcodedExamples(): string { /* current heredoc moved verbatim */ }
```

```php
// config/services.php — gemini block
'gemini' => [
    // ... existing keys ...
    'negative_examples_enabled' => (bool) env('GEMINI_NEGATIVE_EXAMPLES_ENABLED', true),
    'negative_examples_limit'   => (int)  env('GEMINI_NEGATIVE_EXAMPLES_LIMIT', 5),
],
```

```php
// AppServiceProvider — updated singleton closure
$this->app->singleton(GeminiPromptBuilder::class, function ($app) {
    return new GeminiPromptBuilder(
        catalog: $app->make(PepCatalogService::class),
        negativeExamplesService: $app->make(\App\Services\DescartadosAnalisisService::class),
        negativeExamplesLimit: (int) config('services.gemini.negative_examples_limit', 5),
    );
});
```

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit | Router branches (REQ-5..REQ-10) | Extend `GeminiPromptBuilderTest`. Mock `DescartadosAnalisisService` via `createMock()`. Override config per-test with `config(['services.gemini.negative_examples_enabled' => …])`. Use `Log::spy()` for log assertions. Build fake `ResultadoScraping` with `new ResultadoScraping(['titulo' => …, 'gemini_motivo' => …, 'gemini_confianza' => …])`. |
| Unit | Cache reuse (REQ-6) | Mock service with `->expects($this->once())->method('getNegativeExamples')`; call `filtroPEP()` twice on same builder; verify single invocation. New instance → assert called twice across both. |
| Unit | Backward compat (REQ-10) | All 32 existing tests run unchanged. `phpunit.xml` env flag = false guarantees fallback even if DI accidentally injects. |
| Integration | None required | Real `DescartadosAnalisisService` exercised via existing `DescartadosAnalisisServiceTest`; the builder change is pure formatter logic. |

**Test naming** (Spanish convention per AGENTS.md): `test_construye_ejemplos_hardcodeados_cuando_servicio_es_null`, `test_construye_ejemplos_dinamicos_cuando_flag_habilitado`, `test_cache_reusado_en_multiples_llamadas_misma_instancia`, `test_nueva_instancia_consulta_db_de_nuevo`, `test_loguea_cuenta_cuando_inyecta_dinamicos`, `test_respeta_limite_de_ejemplos_negativos`, `test_formato_neg_op_es_correcto`, `test_caracteres_especiales_en_titulo_se_preservan`.

## Migration / Rollout

**Deploy**:
1. Merge PR → migrations none → workers restart picks up new code.
2. First analysis call per worker emits `Log::info('gemini.negative_examples.injected', ['count' => N])`.
3. Monitor 14d: track `precisionGeneral()` baseline (87.5% descarte ≥85) → expect ↓.

**Rollback** (zero-deploy):
- Set `GEMINI_NEGATIVE_EXAMPLES_ENABLED=false` in prod `.env` → restart workers → reverts to hardcoded.
- No data migration to undo. Persisted analysis results remain valid (only the prompt was affected, not the schema).

**Code rollback**: revert merge commit. Nullable params + null-default behavior tolerate absence.

## Performance & Cost

| Metric | Impact |
|---|---|
| DB queries | +1 per worker lifetime (cached after first call) |
| Tokens per call | +~400 (0.04% of 1M Flash window) |
| Latency | First call: +1 indexed query on `resultados_scraping (descartado, gemini_confianza)`. Subsequent: 0. |
| API cost | <0.5% per call increase |

## Open Questions

- [ ] **Confirmed: singleton staleness acceptable** — bounded by worker max-time (typically <24h on Horizon). If precision regression observed AND traced to stale examples, switch to `bind()` in AppServiceProvider (single-line change).
- [ ] **PostgreSQL `gemini_confianza` index** — verified by reading `getNegativeExamples()`: filter on `descartado=true AND gemini_confianza >= 70 ORDER BY gemini_confianza DESC LIMIT 5`. Confirm a composite index exists or query plan is acceptable. (Out of scope for this change but flag for tasks phase if missing.)

## Out of Scope

- Cache `getNegativeExamples()` in Redis with TTL (would require modifying `DescartadosAnalisisService` — explicit "NOT cached" docblock decision)
- Per-category negative examples (filter by article `categoria`)
- Recency-weighted ranking
- A/B precision measurement framework
- Positive (PEP+) example injection
