# gemini-filtro Specification

**Change**: `gemini-confidence-persistence`
**Capability**: `gemini-filtro` (New)
**Date**: 2026-05-15

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

## Out of Scope

- `DescartadosAnalisisService` — unchanged
- `GeminiAnalisisService` — writes to `cambios`, separate concern
- Dashboard or CLI presentation — already consumes the column
- Schema migrations — column already exists
