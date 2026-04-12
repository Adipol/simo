# Design: normalizacion-nombres

## Technical Approach

Pure stateless service `NombreNormalizador` returns a `final readonly NombreNormalizadoDTO` with three forms: `original`, `normalized` (Title Case, accents preserved), `matchingKey` (lowercase, accent-stripped). Two hybrid columns are added (`gemini_nombre_normalizado`, `corregido_nombre_normalizado`), populated on write from `GeminiFiltroService::persistirResultado()` and `Resultados::guardarFeedbackIncorrecto()`. A backfill Artisan command handles existing rows. Original columns are never mutated.

## Architecture Decisions

| # | Decision | Choice | Rejected | Rationale |
|---|----------|--------|----------|-----------|
| ADR-1 | Service shape | Pure class, constructor-injectable, no state | Facade / static helper / Action | Trivially testable, DI-friendly, no global state |
| ADR-2 | DTO type | `final readonly class` with 3 props + `equals()` + `empty()` | Array, generic value object | Immutable, typed, matches REQ-3 |
| ADR-3 | Accent stripping | Explicit `strtr()` map (á→a, é→e, …, ñ→n, ü→u) | `Str::ascii()` / `iconv` | Deterministic across PHP/ICU versions (REQ NF-DET-1) |
| ADR-4 | Title case | `mb_convert_case($s, MB_CASE_TITLE, 'UTF-8')` | `ucwords` / manual explode | Native UTF-8 handling for "maría"→"María"; splits on hyphen/apostrophe automatically |
| ADR-5 | Title removal | Single case-insensitive regex anchored at `^`, optional trailing period, optional whitespace | `str_starts_with` / word explode | Handles both `Dr.` and `Don` in one pass, enforces REQ-13 (position constraint) |
| ADR-6 | Rule order | R1→R2→R3→R4→R7→R5→R6 | Spec order R1→R7→R6 | Trailing punctuation (R7) must run before Title Case (R5) so `"pérez."` → `"pérez"` → `"Pérez"`; matches NF-DET-2 intent |
| ADR-7 | Storage | Two separate migrations (one per table) | Single combined migration | Clean rollback per table, clearer git history |
| ADR-8 | Command scope | Single `simo:normalizar-nombres` processes both tables sequentially | Two commands | Less friction for operator, REQ-BF-11 satisfied in one run |
| ADR-9 | Failure mode | try/catch around `normalize()` inside persist; log warning, store `null` | Propagate exception | REQ-5 graceful degradation; persistence must not abort |
| ADR-10 | Backfill pagination | `chunkById` (not `chunk`) | `chunk`, raw LIMIT/OFFSET | Stable pagination while updating; won't skip rows |

## Data Flow

    Gemini JSON ──▶ FiltroResultadoDTO ──▶ GeminiFiltroService::persistirResultado
                                                      │
                                                      ├─ try: NombreNormalizador::normalize($dto->nombre)
                                                      │         └─ on Throwable → Log::warning, $norm = null
                                                      ▼
                                           $record->update([... gemini_nombre, gemini_nombre_normalizado])

    User modal ──▶ Resultados::guardarFeedbackIncorrecto
                        │
                        ├─ app(NombreNormalizador)->normalizeNullable($this->feedbackNombreCorregido)
                        ▼
              ClasificacionFeedback::updateOrCreate([... corregido_nombre, corregido_nombre_normalizado])

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/Normalization/NombreNormalizador.php` | Create | Pure service, 7 rules, `normalize()` + `normalizeNullable()` |
| `app/Services/Normalization/DTOs/NombreNormalizadoDTO.php` | Create | `final readonly` DTO, `equals()`, `empty()` |
| `database/migrations/2026_04_11_000004_add_gemini_nombre_normalizado_to_resultados_scraping.php` | Create | Column + index after `gemini_nombre` |
| `database/migrations/2026_04_11_000005_add_corregido_nombre_normalizado_to_clasificaciones_feedback.php` | Create | Column + index after `corregido_nombre` |
| `app/Models/ResultadoScraping.php` | Modify | Add `gemini_nombre_normalizado` to `$fillable` |
| `app/Models/ClasificacionFeedback.php` | Modify | Add `corregido_nombre_normalizado` to `$fillable` |
| `app/Services/Gemini/GeminiFiltroService.php` | Modify | Inject normalizador; normalize in `persistirResultado` with try/catch |
| `app/Livewire/Scraper/Resultados.php` | Modify | Normalize `feedbackNombreCorregido` via `app()` resolution in `guardarFeedbackIncorrecto` |
| `app/Console/Commands/NormalizarNombresCommand.php` | Create | `simo:normalizar-nombres {--chunk=500} {--dry-run} {--force}` |
| `tests/Unit/Services/Normalization/NombreNormalizadorTest.php` | Create | ≥20 cases: one per rule + edge cases + determinism |
| `tests/Unit/Services/Normalization/NombreNormalizadoDTOTest.php` | Create | Constructor, `equals()`, `empty()` |
| `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` | Create | Persist integration, null propagation, failure path |
| `tests/Feature/Livewire/ResultadosFeedbackNormalizacionTest.php` | Create | Feedback upsert populates both columns |
| `tests/Feature/Commands/NormalizarNombresCommandTest.php` | Create | Dry-run, force, idempotency, error resilience, both tables |

## Interfaces / Contracts

```php
final class NombreNormalizador {
    private const TITLE_REGEX = '/^(?:dr\.?|dra\.?|lic\.?|licdo\.?|licda\.?|ing\.?|mg\.?|mtra\.?|mtro\.?|prof\.?|profa\.?|sr\.?|sra\.?|srta\.?|ab\.?|abg\.?|don|doña)\s+/iu';
    private const ACCENT_MAP = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','ñ'=>'n','Ñ'=>'n','ü'=>'u','Ü'=>'u','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'];
    public function normalize(string $name): NombreNormalizadoDTO;
    public function normalizeNullable(?string $name): ?NombreNormalizadoDTO;
}
final readonly class NombreNormalizadoDTO {
    public function __construct(public string $original, public string $normalized, public string $matchingKey) {}
    public static function empty(): self { return new self('', '', ''); }
    public function equals(self $o): bool { return $this->matchingKey === $o->matchingKey; }
}
```

Examples: `"Dr. Juan Pérez"` → `{normalized:"Juan Pérez", matchingKey:"juan perez"}`. `"García-López"` → `{matchingKey:"garcia-lopez"}`.

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `NombreNormalizador` (7 rules × 2+ cases, nullable, single-word, hyphens, apostrophes, title-in-middle, multi-title, non-Spanish, determinism ×5) | Pest data providers, pure assertions |
| Unit | DTO `equals`, `empty`, immutability | Pest, direct |
| Feature | `GeminiFiltroService::persistirResultado` populates both columns, null propagation, normalizer throw → warning + null | Pest, DB facts, Log::fake |
| Feature | `Resultados::guardarFeedbackIncorrecto` upsert populates `corregido_nombre_normalizado`; empty→null | Livewire::test |
| Feature | `simo:normalizar-nombres` dry-run count, `--force` updates, idempotent 2nd run = 0, `--chunk=100`, error on one row continues, both tables touched | `artisan()->expectsOutput()` |

## Migration / Rollout

1. Ship migrations (nullable columns, non-breaking).
2. Ship code (service + integration points).
3. Run `php artisan simo:normalizar-nombres --dry-run` then without flag on low-traffic window.
4. Rollback = `migrate:rollback` + `git revert`; originals untouched → zero data loss.

## Open Questions

None — all decisions resolved from proposal/spec/exploration.
