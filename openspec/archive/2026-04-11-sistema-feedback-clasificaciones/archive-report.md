# Archive Report: Sistema Feedback Clasificaciones

**Change**: `sistema-feedback-clasificaciones`  
**Archived**: 2026-04-11  
**Status**: ✅ COMPLETE & VERIFIED  
**Duration**: Same-day (propose → spec → design → apply → verify → archive)

---

## Summary

Built a user feedback system for Gemini classifications in scraper results, allowing authorized analysts (admin, supervisor) to mark PEP/OPI/NO_REL classifications as "correcto" or "incorrecto" directly from the results table. The system captures what Gemini said at the time of feedback (JSON snapshot), supports upsert behavior (one feedback per user per resultado), and provides visual indicators per row. The implementation extends the existing `Resultados` Livewire component following the established `verAnalisisId` modal pattern, with a new `clasificaciones_feedback` table using surrogate PK + unique tuple constraint.

---

## Capabilities Delivered

| Capability | Type | Status |
|------------|------|--------|
| `classification-feedback` | NEW | ✅ |
| `scraper-resultados-ui` | MODIFIED | ✅ |

---

## Files Created (15 new)

| File | Purpose |
|------|---------|
| `database/migrations/2026_04_11_000010_create_clasificaciones_feedback_table.php` | Table with surrogate PK, FKs, unique constraint, indexes |
| `app/Enums/TipoFeedback.php` | BackedEnum: Correcto, Incorrecto |
| `app/Enums/CategoriaCorreccion.php` | BackedEnum: PEP, OPI, NoRel |
| `app/Models/ClasificacionFeedback.php` | Model with fills, casts, scopes, relations |
| `app/Models/ResultadoScraping.php` | Modified: +feedback(), +toGeminiSnapshot(), +withFeedbackFromUser() scope |
| `app/Models/User.php` | Modified: +clasificacionesFeedback() |
| `database/seeders/RolesPermisosSeeder.php` | Modified: +permission to admin+supervisor |
| `app/Livewire/Scraper/Resultados.php` | Modified: +feedback props, methods, eager loading |
| `resources/views/livewire/scraper/resultados.blade.php` | Modified: +feedback buttons, badges, modal |
| `database/factories/ClasificacionFeedbackFactory.php` | Factory for tests |
| `tests/Unit/Enums/TipoFeedbackTest.php` | Enum tests |
| `tests/Unit/Enums/CategoriaCorreccionTest.php` | Enum tests |
| `tests/Unit/Models/ClasificacionFeedbackTest.php` | Model + scope tests |
| `tests/Feature/Livewire/Scraper/ResultadosFeedbackTest.php` | Livewire feature tests |
| `tests/Unit/Models/ResultadoScrapingTest.php` | Extended with snapshot/feedback scope tests |

**Files Modified**: 6 (ResultadoScraping, User, RolesPermisosSeeder, Resultados, resultados blade, +integration test file)

---

## Test Coverage

| Metric | Count |
|--------|-------|
| New tests added | 52 |
| New test assertions | 108+ |
| Total test suite (after) | 262 passed |
| Pre-existing failures | 6 (ExampleTest × 1, ProfileTest × 5 — unrelated) |
| New failures | 0 |
| Net regression | **NONE** |

**Critical coverage verified**: N+1 prevention, cascade delete, restrict delete, permission gate, upsert, validation, pre-fill modal, UI visibility.

---

## Design Decisions (8 ADRs)

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| 1 | Primary key | Surrogate `id` + UNIQUE `(resultado_id, usuario_id)` | Laravel-idiomatic; `updateOrCreate`, relations, factories all work without hacks |
| 2 | `tipo` storage | `VARCHAR(12)` + `TipoFeedback` BackedEnum cast | Portable SQLite/PG; type safety in PHP |
| 3 | `corregido_categoria` | Separate `CategoriaCorreccion` BackedEnum | Spec exact values; decoupled from Gemini output strings |
| 4 | Snapshot | JSON column via `toGeminiSnapshot()` builder | JSON matches spec; builder method is testable |
| 5 | Eager loading | `withFeedbackFromUser(int $userId)` scope | Avoids Auth-in-model anti-pattern; explicit at call site |
| 6 | Modal pattern | Extend existing Resultados component | Matches `verAnalisisId` pattern already established |
| 7 | Permission guard | `@can` in blade + `$this->authorize()` in every action | Double-layer guard; matches existing project convention |
| 8 | FK delete rules | CASCADE on resultado, RESTRICT on usuario | Spec NFR mandates exact rules; verified by tests |

---

## Notable Moments

1. **Decision pivot (exploration)**: Initial exploration recommended a separate `FeedbackModal` component with event dispatch. During design, pivoted to extending `Resultados` directly — matching the existing `verAnalisisId` modal pattern already in the blade. Cleaner, fewer files, consistent with project conventions.

2. **Surrogate vs composite PK**: Exploration originally planned composite PK via `HasCompositePrimaryKey` trait. Design resolved to surrogate `id` + UNIQUE constraint instead — simpler, Laravel-idiomatic, no third-party trait needed.

3. **Minor suggestion deferred**: Verify report flagged that modal snapshot display reads from feedback relation (null if no existing feedback) instead of directly from `ResultadoScraping`. Non-blocking — future improvement opportunity.

4. **Scope return type warning**: `scopeWithFeedbackFromUser()` returns `void` — works but limits chainability. Not fixed in this change; low priority.

---

## Deferred Items (Out of Scope)

The following are explicitly NOT part of this change and require separate changes:

- **Dashboard de métricas** — aggregation queries over feedback data (precision rate = correct / total)
- **Notificaciones/email** — when a classification is disputed
- **Feedback masivo** — bulk feedback operations
- **Historial de feedback por usuario** — UI view of a user's past feedback
- **Detección de familiares** — separate PEP Monitor feature
- **Detección de cambios de cargo** — separate feature
- **Detección de familiares** — separate feature
- **spaCy lematización en scraper** — already completed as `lematizacion-pep-opi`
- **UI de gestión de cargos PEP** — separate feature
- **Ajuste adaptativo del prompt Gemini** — based on accumulated feedback data
- **Feedback sobre tabla `cambios` de PEP Monitor** — separate feature

---

## Final Metrics

| Metric | Value |
|--------|-------|
| Tasks completed | 57/57 |
| Requirements (MUST) | 26/26 |
| Design ADRs | 8/8 |
| New test files | 6 |
| New tests | 52 |
| Total assertions | 634+ |
| New files created | 15 |
| Files modified | 6 |
| Phases | 9 |

**Estimated lines added**: ~1,800 (models, migrations, tests, blade)

---

## Dependencies Satisfied

| Dependency | Status |
|------------|--------|
| `lematizacion-pep-opi` | ✅ COMPLETE — `gemini_analyzed`, `gemini_is_pep`, `gemini_categoria`, `gemini_confianza`, `gemini_nombre`, `gemini_cargo`, `gemini_motivo` fields already exist |
| Spatie laravel-permission | ✅ Already installed and configured |
| Livewire 4 + WithPagination | ✅ Already in use in `Resultados` |

---

## Archive Location

```
openspec/archive/2026-04-11-sistema-feedback-clasificaciones/
├── proposal.md
├── exploration.md
├── specs/
│   └── spec.md
├── design.md
├── tasks.md
├── verify-report.md
└── archive-report.md (this file)
```

---

**Archive date**: 2026-04-11  
**Archived by**: SDD sdd-archive phase  
**Source of truth**: Filesystem (openspec/archive) — this report
