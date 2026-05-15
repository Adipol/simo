# Design: Gemini Confidence Persistence

**Change**: `gemini-confidence-persistence`
**Date**: 2026-05-15

## Technical Approach

Encapsulate `MAX(persona.confianza)` aggregation in `FiltroResultadoDTO::maxConfianza()`, call it in the existing `GeminiFiltroService::persistirResultado()` update array, and ship a same-PR Artisan command `simo:backfill-gemini-confianza` that backfills the 160 historical rows via Eloquent `chunkById(100)`. Strict TDD: tests precede production code; SQLite + Postgres CI both required green.

## Architecture Decisions

| Decision | Choice | Alternative rejected | Rationale |
|----------|--------|----------------------|-----------|
| Aggregation | `MAX(persona.confianza)` | first-passer / per-persona avg | Aligns with `gemini_is_pep = ANY persona >= 70`; single point of change |
| Aggregation seam | DTO method `maxConfianza(): ?int` | inline in service | Testable in isolation; semantics live where personas live |
| Persona confianza type | keep `int` default `0` | switch to `?int` | Existing contract; downstream filter `>= 70` excludes `0` correctly; avoids breaking `ResultadoPersona::create` and threshold compare |
| `null` semantics | only when `personas === []` | also when all `confianza=0` | Spec "all-null" maps to "all-zero" in current `int` contract — return `0`; DTO test documents this |
| Backfill location | same PR as forward fix | separate PR | Atomicity; dashboard recovers on deploy without follow-up |
| Backfill aggregation | PHP `max()` over Eloquent `personas()` collection | `DB::raw` / subquery | Cross-driver portability (SQLite + Postgres); no driver-specific SQL |
| Chunk size | `chunkById(100)` | `cursor()` / no chunk | 160 rows trivial today; portable; bounded memory at scale |

## Data Flow

```
Forward fix (new analyses):
  GeminiFiltroService::procesarRegistro()
      → FiltroResultadoDTO::fromArray()
      → persistirResultado()
            ├── ResultadoPersona::create() per persona  (unchanged)
            └── $record->update([..., 'gemini_confianza' => $dto->maxConfianza()])  ← NEW

Backfill (one-time):
  simo:backfill-gemini-confianza [--dry-run]
      → ResultadoScraping::where('gemini_analyzed', true)
                          ->whereNull('gemini_confianza')
                          ->with('personas:id,resultado_scraping_id,confianza')
                          ->chunkById(100, fn($rows) => ...)
      → for each row: max(personas.pluck('confianza'))
                       └── if rows empty → skip (no_personas++)
                       └── else          → $row->update(['gemini_confianza' => $max]) (updated++)
      → final report: scanned / updated / skipped_no_personas / mode
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/Gemini/DTOs/FiltroResultadoDTO.php` | Modify | Add `maxConfianza(): ?int` method |
| `app/Services/Gemini/GeminiFiltroService.php` | Modify | Add `'gemini_confianza' => $dto->maxConfianza()` to `persistirResultado()` update array |
| `app/Console/Commands/BackfillGeminiConfianza.php` | Create | Artisan `simo:backfill-gemini-confianza {--dry-run}` |
| `tests/Unit/Gemini/FiltroResultadoDTOTest.php` | Create | 5 cases for `maxConfianza()` |
| `tests/Feature/Gemini/GeminiFiltroServiceTest.php` | Modify | Add 4 cases (single, multi, zero personas, pre-filter) |
| `tests/Feature/Console/BackfillGeminiConfianzaTest.php` | Create | 6 cases (update, skip-no-personas, idempotent, unanalyzed-untouched, dry-run, report counters) |

## Interfaces / Contracts

```php
// FiltroResultadoDTO::maxConfianza
public function maxConfianza(): ?int
{
    if ($this->personas === []) {
        return null;
    }

    return max(array_map(
        fn (PersonaDetectadaDTO $p) => $p->confianza,
        $this->personas,
    ));
}
```

Spec mapping note: "all-null confianza → NULL" (spec scenario) maps to "all-zero → returns `0`" because `PersonaDetectadaDTO::$confianza` is `int` with `0` default. `0` is a valid persisted value (column is `unsignedTinyInteger nullable`); downstream `>= 70` filter excludes it. Only `personas === []` returns `null`.

```php
// BackfillGeminiConfianza::handle (sketch)
public function handle(): int
{
    $dryRun  = (bool) $this->option('dry-run');
    $scanned = 0; $updated = 0; $skippedNoPersonas = 0;

    ResultadoScraping::query()
        ->where('gemini_analyzed', true)
        ->whereNull('gemini_confianza')
        ->with(['personas:id,resultado_scraping_id,confianza'])
        ->chunkById(100, function ($rows) use (&$scanned, &$updated, &$skippedNoPersonas, $dryRun) {
            foreach ($rows as $row) {
                $scanned++;
                $confianzas = $row->personas->pluck('confianza');
                if ($confianzas->isEmpty()) {
                    $skippedNoPersonas++;
                    continue;
                }
                if (! $dryRun) {
                    $row->update(['gemini_confianza' => (int) $confianzas->max()]);
                }
                $updated++;
            }
        });

    $this->table(['Metric', 'Count'], [
        ['Scanned', $scanned],
        ['Updated', $updated],
        ['Skipped (no personas)', $skippedNoPersonas],
        ['Mode', $dryRun ? 'dry-run' : 'live'],
    ]);

    return self::SUCCESS;
}
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `FiltroResultadoDTO::maxConfianza` | Pure DTO construction; 5 cases (empty→null, single→value, multi→max, all-zero→0, mixed→max) |
| Feature (service) | `persistirResultado` writes `gemini_confianza` | Mock `GeminiService`; 4 cases (single, multi MAX, zero personas→null, pre-filter→null untouched) |
| Feature (command) | `simo:backfill-gemini-confianza` | Factory fixtures via `RefreshDatabase`; 6 cases — update, skip-no-personas, idempotency (re-run = 0 updated), unanalyzed untouched, `--dry-run` writes nothing, report counters |

CI matrix (existing, branch-protected): `test-sqlite` AND `test-pgsql` both required green.

## Migration / Rollout

- **Forward fix**: no schema change; column already exists. Deploy → new analyses populate immediately.
- **Backfill**: run `php artisan simo:backfill-gemini-confianza --dry-run` first → verify counts → run live. Idempotent re-run is safe.
- **Rollback**: revert single-line addition in `persistirResultado()`. Optional data reset: `ResultadoScraping::where('gemini_analyzed', true)->update(['gemini_confianza' => null])` (source data in `resultado_personas` untouched, re-backfill safe).

## Performance

- Forward fix: 1 method call, 1 array key — zero overhead.
- Backfill: 160 rows / 100 = 2 chunks × (1 SELECT + N UPDATE) ≈ 320 queries. Eager-loaded personas via `with('personas:id,resultado_scraping_id,confianza')` avoids N+1. Linear scaling: 10k rows ≈ 20k queries — acceptable for offline command.

## Open Questions

None. Relationship `ResultadoScraping::personas()` confirmed (HasMany, fk `resultado_scraping_id`). All other contracts verified against codebase.
