# Proposal: normalizacion-nombres

## Intent

Gemini extracts raw names from documents without normalizing them — same person can appear as `"Dr. Juan Pérez"`, `"JUAN PÉREZ"`, or `"Juan Perez"`. Without a canonical form, deduplication is impossible and future KPIs (unique persons detected) would be wrong.

This change introduces a pure normalization service, persists a normalized form alongside each raw name, and backfills existing records — without modifying any existing display logic or dashboard queries.

## Scope

### In Scope
- `NombreNormalizador` service — 7 SAFE rules, pure function, no dependencies
- `NombreNormalizadoDTO` — readonly DTO: `original`, `normalized`, `matchingKey`
- Migration: `gemini_nombre_normalizado` (VARCHAR 300, nullable, indexed) → `resultados_scraping`
- Migration: `corregido_nombre_normalizado` (VARCHAR 300, nullable, indexed) → `clasificaciones_feedback`
- Model updates: both models add new columns to `$fillable`
- Integration: `GeminiFiltroService::persistirResultado()` normalizes on every new record
- Integration: `Resultados::guardarFeedbackIncorrecto()` normalizes `corregido_nombre` on feedback save
- Backfill command: `php artisan simo:normalizar-nombres` (`--chunk`, `--dry-run`, `--force`)
- Unit tests: `NombreNormalizadorTest` — 20+ cases, one per rule + edge cases
- Feature tests: Gemini persist integration, feedback integration, backfill idempotency

### Out of Scope
- Dashboard GROUP BY normalized column → deferred to `dashboard-metricas-normalizadas`
- "Unique persons detected" KPI → deferred to `dashboard-metricas-normalizadas`
- Initials expansion, phonetic matching, nickname expansion — unsafe in v1
- Country-specific rules beyond Latin American Spanish
- `cargo` normalization — different problem
- `personas` table / "merge persons" workflow — v2

## Capabilities

### New Capabilities
- `name-normalization`: Pure normalization service producing a DTO with 3 name forms (original, normalized display, matching key) using 7 SAFE rules for Spanish names

### Modified Capabilities
- `scraping-data-pipeline`: `GeminiFiltroService::persistirResultado()` now normalizes names on persist and stores `gemini_nombre_normalizado`
- `feedback-classification`: `Resultados::guardarFeedbackIncorrecto()` now normalizes `corregido_nombre` and stores `corregido_nombre_normalizado`

## Approach

Pure service + hybrid storage + separate backfill command.

1. `NombreNormalizador::normalize(string $name): NombreNormalizadoDTO` — applies R1–R7 in order, stateless
2. Both DB tables gain a `_normalizado` column; originals are NEVER modified
3. Integration points call the service on write (no read-path change)
4. Migration runs in seconds (nullable column); backfill runs separately via Artisan command
5. Dashboard deferred — no breaking change during backfill window

**Rule pipeline order**: R1 trim → R2 collapse spaces → R3 remove academic titles → R4 remove courtesy titles → R5 title case → R7 remove trailing punctuation → R6 accent-strip (matching key only)

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/Normalization/NombreNormalizador.php` | New | Pure service, 7 rules |
| `app/Services/Normalization/DTOs/NombreNormalizadoDTO.php` | New | Readonly DTO, 3 forms |
| `database/migrations/*_add_gemini_nombre_normalizado_to_resultados_scraping.php` | New | Column + index |
| `database/migrations/*_add_corregido_nombre_normalizado_to_clasificaciones_feedback.php` | New | Column + index |
| `app/Models/ResultadoScraping.php` | Modified | Add to `$fillable` |
| `app/Models/ClasificacionFeedback.php` | Modified | Add to `$fillable` |
| `app/Services/Gemini/GeminiFiltroService.php` | Modified | Normalize on `persistirResultado()` |
| `app/Livewire/Scraper/Resultados.php` | Modified | Normalize on `guardarFeedbackIncorrecto()` |
| `app/Console/Commands/NormalizarNombresCommand.php` | New | Backfill command |
| `tests/Unit/Services/NombreNormalizadorTest.php` | New | 20+ unit test cases |
| `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` | New | Persist integration test |
| `tests/Feature/Commands/NormalizarNombresCommandTest.php` | New | Backfill + dry-run tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| False positive merge from SAFE rules | Low | v1 rules are conservative — no initials expansion, no phonetic |
| Non-Spanish names degraded | Low | Title rules only strip known prefixes; international names pass through |
| Backfill performance on large tables | Low | `--chunk=500` default, idempotent, can run off-hours |
| NULL normalized column during backfill window breaks dashboard | None | Dashboard still reads `gemini_nombre` (unchanged) in this change |
| Migration fails on SQLite test environment | Low | Use portable schema syntax (`string()`, not pg-specific types) |

## Rollback Plan

1. `php artisan migrate:rollback` — drops both `_normalizado` columns and their indexes
2. `git revert` — restores `GeminiFiltroService`, `Resultados`, models to pre-change state
3. No data loss — original `gemini_nombre` and `corregido_nombre` are never modified
4. Backfill rollback: N/A — dropping the column removes all backfilled data cleanly

## Dependencies

- `lematizacion-pep-opi` ✅ — `gemini_nombre` field exists in `resultados_scraping`
- `sistema-feedback-clasificaciones` ✅ — `corregido_nombre` field exists in `clasificaciones_feedback`
- `dashboard-estadisticas` ✅ — dashboard stable, not touched by this change

## Success Criteria

- [ ] `NombreNormalizador::normalize('Dr. Juan Pérez')` returns DTO: `original='Dr. Juan Pérez'`, `normalized='Juan Pérez'`, `matchingKey='juan perez'`
- [ ] All 7 rules have ≥ 2 unit test cases each
- [ ] `GeminiFiltroService` auto-populates `gemini_nombre_normalizado` on every new record (no manual step)
- [ ] `Resultados::guardarFeedbackIncorrecto()` auto-populates `corregido_nombre_normalizado`
- [ ] Backfill command runs idempotently: second run reports 0 records updated
- [ ] `--dry-run` shows preview count without writing to DB
- [ ] `php artisan test` passes (316+ tests, no regressions)
- [ ] `./vendor/bin/pint` exits clean
- [ ] Both migrations run on SQLite (tests) and PostgreSQL (prod)
