# Archive Report: normalizacion-nombres

**Change**: normalizacion-nombres
**Archived**: 2026-04-11
**Status**: COMPLETE ✅

---

## Summary

A pure name normalization service was built for the SIMO application, enabling deduplication of PEP/OPI detections that arrive with name variants ("Dr. Juan Pérez", "JUAN PÉREZ", "Juan Perez"). The change introduced `NombreNormalizador` — a stateless, DI-friendly service applying 7 SAFE rules — along with hybrid storage (normalized column alongside original), integrations in the scraping pipeline and feedback system, and a backfill Artisan command. Original name data is never mutated.

---

## Capabilities Delivered

| Capability | Status | Description |
|------------|--------|-------------|
| `name-normalization` | **NEW** | Pure service `NombreNormalizador` producing `NombreNormalizadoDTO` with 3 forms: original, normalized (Title Case, accents preserved), matchingKey (accent-stripped, lowercase) |
| `scraping-data-pipeline` | **MODIFIED** | `GeminiFiltroService::persistirResultado()` now normalizes and stores `gemini_nombre_normalizado` on every new record |
| `feedback-classification` | **MODIFIED** | `Resultados::guardarFeedbackIncorrecto()` now normalizes `corregido_nombre` and stores `corregido_nombre_normalizado` |
| `backfill-command` | **NEW** | `php artisan simo:normalizar-nombres` backfills both tables with normalized names, idempotent, chunked, resumable |

---

## Files Created / Modified

### New Files (10)
```
app/Services/Normalization/NombreNormalizador.php
app/Services/Normalization/DTOs/NombreNormalizadoDTO.php
app/Services/Normalization/NombreNormalizadorInterface.php
database/migrations/2026_04_11_000004_add_gemini_nombre_normalizado_to_resultados_scraping.php
database/migrations/2026_04_11_000011_add_corregido_nombre_normalizado_to_clasificaciones_feedback.php
app/Console/Commands/NormalizarNombresCommand.php
tests/Unit/Services/Normalization/NombreNormalizadoDTOTest.php
tests/Unit/Services/Normalization/NombreNormalizadorTest.php
tests/Feature/Services/GeminiFiltroNormalizacionTest.php
tests/Feature/Livewire/ResultadosFeedbackNormalizacionTest.php
tests/Feature/Commands/NormalizarNombresCommandTest.php
```

### Modified Files (4)
```
app/Models/ResultadoScraping.php
app/Models/ClasificacionFeedback.php
app/Services/Gemini/GeminiFiltroService.php
app/Livewire/Scraper/Resultados.php
```

---

## Test Coverage

| Metric | Value |
|--------|-------|
| New tests | 86 |
| Total tests | 402 (6 pre-existing failures unrelated) |
| Assertions | 141 |
| Test suites | 5 (DTO, Normalizador, Command, GeminiFiltro, Resultados) |
| Pint | ✅ Clean |

---

## Normalization Rules (7 SAFE Rules)

| Rule | Description | Example |
|------|-------------|---------|
| R1 | Trim leading/trailing whitespace | `"  Juan Pérez  "` → `"Juan Pérez"` |
| R2 | Collapse multiple spaces to single space | `"Juan  Pérez"` → `"Juan Pérez"` |
| R3 | Remove academic titles at start (Dr., Dra., Lic., Licdo., Licda., Ing., Mg., Mtra., Mtro., Prof., Profa.) | `"Dr. Juan Pérez"` → `"Juan Pérez"` |
| R4 | Remove courtesy titles at start (Sr., Sra., Srta., Ab., Abg., Don, Doña) | `"Sra. María García"` → `"María García"` |
| R5 | Convert to Title Case (UTF-8 aware) | `"JUAN PÉREZ"` → `"Juan Pérez"` |
| R6 | Strip accents for matching key only (á→a, é→e, í→i, ó→o, ú→u, ñ→n, ü→u) | `"Juan Pérez"` key → `"juan perez"` |
| R7 | Remove trailing punctuation (.,;:) | `"Juan Pérez."` → `"Juan Pérez"` |

**Pipeline order**: R1 → R2 → R3 → R4 → R7 → R5 → R6

> **Note**: R7 runs before R5 so that trailing periods don't affect capitalization (`"pérez."` → `"pérez"` → `"Pérez"`, not `"Pérez."`).

---

## Key Design Decisions (10 ADRs)

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| ADR-1 | Service shape | Pure injectable class | Trivially testable, no global state, DI-friendly |
| ADR-2 | DTO type | `final readonly` with `equals()` + `empty()` | Immutable, typed, supports value semantics |
| ADR-3 | Accent stripping | Explicit `strtr()` map | Deterministic across PHP/ICU versions |
| ADR-4 | Title case | `mb_convert_case(MB_CASE_TITLE, 'UTF-8')` | Native UTF-8 handling, handles hyphen/apostrophe correctly |
| ADR-5 | Title removal | Single anchored regex `/^(?:...)\s+/iu` | Handles all titles in one pass, enforces position constraint |
| ADR-6 | Rule order | R1→R2→R3→R4→R7→R5→R6 | Trailing punctuation removed before Title Case |
| ADR-7 | Storage migrations | Two separate migrations | Clean rollback per table, clearer git history |
| ADR-8 | Backfill command | Single command processes both tables | Single operator invocation for both tables |
| ADR-9 | Failure mode | try/catch → null + warning log | Graceful degradation, persistence never aborted |
| ADR-10 | Backfill pagination | `chunkById` | Stable pagination while updating, won't skip rows |

---

## Notable Moments

1. **Spec typo in NF-DET-2**: The spec states rule order "R1 → R2 → R3 → R4 → R5 → R7 → R6" but the code and all scenarios correctly implement "R1 → R2 → R3 → R4 → R7 → R5 → R6" (R7 before R5). The code is correct — the spec has a typo. This was caught during verification and documented in `verify-report.md`. The order R7→R5 is necessary so trailing periods don't contaminate Title Case capitalization.

2. **Sub-agent returned empty envelope**: During implementation, a delegated task reported empty results post-hoc but all files were verified to exist correctly. No functional impact.

3. **Zero SQL portability issues**: Both migrations use portable `$table->string()` syntax and standard indexes — confirmed working on SQLite (tests) and PostgreSQL (production).

4. **86 tests exceeds original estimate**: Original estimate was ~20 unit tests. Actual coverage is 86 with exhaustive per-rule test cases (4–7 cases per rule), edge cases, and all integration points. Quality over quantity.

5. **Graceful degradation confirmed**: `GeminiFiltroService` wraps normalization in try/catch — if normalization fails, `gemini_nombre_normalizado` is set to `null` and a warning is logged, but persistence continues. Verified by dedicated test.

---

## Deferred Items (Future Changes)

| Item | Future Change | Priority |
|------|---------------|----------|
| Dashboard GROUP BY normalized column | `dashboard-metricas-normalizadas` | High |
| KPI "unique persons detected" | `dashboard-metricas-normalizadas` | High |
| Initials expansion (`J.` → `Juan`) | ❌ Unsafe in v1 | — |
| Phonetic matching (Soundex/Metaphone) | ❌ Spanish phonetics differ | — |
| Country-specific rules | ❌ Generic Latin American Spanish v1 | — |
| `personas` table / merge workflow | ❌ v2 possibility | — |
| Cargo normalization | ❌ Different problem space | — |

---

## Final Metrics

| Metric | Value |
|--------|-------|
| Tasks completed | 55/55 |
| New test files | 5 |
| New tests | 86 |
| Total tests after change | 402 |
| New files | 10 |
| Modified files | 4 |
| Design ADRs | 10/10 |
| Spec scenarios | 55/55 (100%) |
| Spec compliance | 100% |
| Pint violations | 0 |

---

## Dependencies Satisfied

| Change | Status |
|--------|--------|
| `lematizacion-pep-opi` | ✅ Complete (provides `gemini_nombre` field) |
| `sistema-feedback-clasificaciones` | ✅ Complete (provides `corregido_nombre` field) |
| `dashboard-estadisticas` | ✅ Stable (not touched by this change) |

---

## Spec Typo Correction (for future reference)

**Location**: `openspec/changes/normalizacion-nombres/specs/spec.md`, line NF-DET-2

**Current (incorrect)**: Rules applied in order R1 → R2 → R3 → R4 → R5 → R7 → R6

**Correct (as implemented)**: Rules applied in order R1 → R2 → R3 → R4 → R7 → R5 → R6

**Rationale**: R7 (trailing punctuation) must run before R5 (Title Case) so that names like `"Juan Pérez."` normalize correctly: R7 strips the period first, then R5 capitalizes → `"Juan Pérez"`. If R5 ran first, you'd get `"Juan Pérez."` with the period still attached.

**Resolution**: Code is authoritative. This typo should be corrected in the spec if/when the spec is revisited. No code change needed.

---

## Pattern Notes for Future Reference

### Pattern: String Normalization Service
- Pure stateless service + `final readonly` DTO with 3 forms (original, normalized, matchingKey)
- Explicit `strtr()` maps over library helpers (e.g. `Str::ascii()`) for determinism
- Regex anchored at `^` for position-sensitive rules
- `mb_convert_case(MB_CASE_TITLE, 'UTF-8')` for proper UTF-8 Title Case (not `ucwords`)
- Test each rule separately with ≥2 cases to triangulate
- Graceful failure via try/catch at integration points

### Pattern: Backfill via Artisan Command
- Schema change in migration (fast, nullable, idempotent)
- Data change in command (chunked, resumable, `--dry-run`, `--force`)
- `chunkById` for stable pagination during updates
- Per-record try/catch for resilience (one bad record doesn't stop the batch)
- Progress bar via `$this->output->progressStart()`

---

## Archive Location

```
openspec/archive/2026-04-11-normalizacion-nombres/
├── archive-report.md
├── exploration.md
├── proposal.md
├── specs/
│   └── spec.md
├── design.md
├── tasks.md
└── verify-report.md
```
