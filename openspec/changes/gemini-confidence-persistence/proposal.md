# Proposal: Gemini Confidence Persistence

## Intent

`resultados_scraping.gemini_confianza` has been NULL for every analyzed row in production (160 rows) since the column was added in `2026_04_05_000001`. Root cause confirmed in exploration: `GeminiFiltroService::persistirResultado()` omits the field from its `update()` call. This blocks `DescartadosAnalisisService::computeConfianza()` (CLI bucket report empty) and the upcoming `T3 gemini-negative-examples-prompt` change (returns zero negative examples). Fix the persistence gap, encode aggregation semantics in the DTO, and backfill historical rows in the same PR so downstream consumers recover immediately.

## Scope

### In Scope
- `FiltroResultadoDTO::maxConfianza(): ?int` — encapsulates `MAX(persona.confianza)` aggregation, returns `null` for empty personas.
- `GeminiFiltroService::persistirResultado()` — adds `'gemini_confianza' => $dto->maxConfianza()` to the parent record update.
- Artisan command `simo:backfill-gemini-confianza` — idempotent backfill from `resultado_personas.confianza` for `gemini_analyzed = true AND gemini_confianza IS NULL` rows; supports `--dry-run`; reports scanned/updated/skipped/errors.
- Strict TDD tests (written first): DTO unit tests (empty / single / multi / all-null / mixed), feature test on `GeminiFiltroService` end-to-end persistence, command test with fixtures.

### Out of Scope
- `DescartadosAnalisisService::getNegativeExamples()` filter logic — unchanged.
- `GeminiAnalisisService` (writes to `cambios`, separate concern).
- Dashboard / CLI presentation — already consumes the column.
- Migrations / new columns — column already exists and is correctly typed.

## Capabilities

### New Capabilities
- `gemini-filtro`: Persistence contract for the Gemini PEP/OPI filter pipeline — defines that every analyzed article record MUST persist aggregated `gemini_confianza` derived from per-persona confidence scores, and that pre-filter rejections leave the field NULL.

### Modified Capabilities
- None. `descartados-analisis` already assumes `gemini_confianza` is populated; this change makes that assumption true without changing its requirements.

## Approach

DTO method (`FiltroResultadoDTO::maxConfianza`) encapsulates the aggregation rule (MAX across personas, NULL when empty). Service calls it in the existing `update()` array — no control flow change. Backfill command uses Eloquent `chunkById(100)` for cross-driver (SQLite/Postgres) safety, applies the same MAX rule via Eloquent relationships, and is gated by `whereNotNull`/`whereNull` filters for idempotency. Strict TDD: DTO and command tests precede production code.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/Gemini/DTOs/FiltroResultadoDTO.php` | Modified | Add `maxConfianza(): ?int` method |
| `app/Services/Gemini/GeminiFiltroService.php` | Modified | Add 1 line in `persistirResultado()` update array |
| `app/Console/Commands/BackfillGeminiConfianza.php` | New | Artisan command `simo:backfill-gemini-confianza` |
| `tests/Unit/Gemini/FiltroResultadoDTOTest.php` | New/Modified | Unit tests for `maxConfianza()` |
| `tests/Feature/Gemini/GeminiFiltroServiceTest.php` | Modified | Assert `gemini_confianza` populated end-to-end |
| `tests/Feature/Console/BackfillGeminiConfianzaTest.php` | New | Command test with fixtures |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| MAX aggregation misrepresents multi-persona articles | Low | Documented decision; aligns with `gemini_is_pep = ANY threshold pass` and `T3 negative examples >= 70` filter. Single point of change in DTO if revisited. |
| Backfill not idempotent → double-update | Low | `whereNull('gemini_confianza')` filter guarantees skip on re-run; command test asserts re-run is no-op. |
| Pre-filter rows incorrectly backfilled | Low | Pre-filter rows have `gemini_analyzed = false` → excluded by command's filter. Verified in exploration. |
| Backfill memory pressure as dataset grows | Low | `chunkById(100)` for cross-driver safety; 160 rows trivial today. |
| Empty `personas` array writes 0 instead of NULL | Low | DTO returns `?int` (NULL on empty); column accepts NULL; covered by unit test. |

## Rollback Plan

1. Forward fix: revert the single-line addition in `GeminiFiltroService::persistirResultado()` and the `maxConfianza()` method. New analyses resume writing NULL; no schema change to revert.
2. Backfill: `gemini_confianza` values can be reset with `UPDATE resultados_scraping SET gemini_confianza = NULL WHERE gemini_analyzed = true` if the aggregation choice needs revisiting. Source data in `resultado_personas.confianza` is untouched, so re-backfill is safe.
3. Command: delete the command file; no migrations or persisted state to undo.

## Dependencies

- None. Uses existing column, existing Eloquent relationships (`ResultadoScraping hasMany ResultadoPersona`), existing test infrastructure.

## Success Criteria

- [ ] New `GeminiFiltroService` analyses populate `gemini_confianza` with `MAX(persona.confianza)` or NULL when personas empty.
- [ ] `simo:backfill-gemini-confianza` is idempotent: second run reports 0 updates.
- [ ] `simo:backfill-gemini-confianza --dry-run` reports counts without writing.
- [ ] After backfill, `DescartadosAnalisisService::computeConfianza()` returns non-empty buckets in CLI dashboard.
- [ ] All tests pass on SQLite (CI `test-sqlite`) and on Postgres (production parity).
- [ ] Production code diff < 100 LOC; total PR < 250 LOC (well under 400-line budget).
