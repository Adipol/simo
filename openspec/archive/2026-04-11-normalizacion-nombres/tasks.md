# Tasks: normalizacion-nombres

## Phase 1: DTO Foundation

- [x] 1.1 RED: Write failing test for NombreNormalizadoDTO constructor and methods
  - Test: constructor sets `original`, `normalized`, `matchingKey` correctly
  - Test: `empty()` returns DTO with three empty strings
  - Test: `equals()` returns true when matchingKey matches
  - Test: `equals()` returns false when matchingKey differs
  - File: `tests/Unit/Services/Normalization/NombreNormalizadoDTOTest.php`
  - Acceptance: tests fail — class doesn't exist

- [x] 1.2 GREEN: Create NombreNormalizadoDTO
  - File: `app/Services/Normalization/DTOs/NombreNormalizadoDTO.php`
  - `final readonly class` with 3 public properties
  - Constructor assigns all three
  - Static `empty()` factory returning new instance with `'', '', ''`
  - `equals(self $other): bool` comparing matchingKey
  - Acceptance: test 1.1 passes

- [x] 1.3 REFACTOR: Pint clean on DTO
  - Command: `./vendor/bin/pint app/Services/Normalization/DTOs/`
  - Acceptance: no violations

## Phase 2: NombreNormalizador Service — TDD per Rule

### R1 (Trim)

- [x] 2.1 RED: Write failing tests for Rule R1 — trim whitespace
  - Test: `'  Juan'` → normalized starts with "Juan"
  - Test: `'Pérez  '` → normalized ends with "Pérez"
  - Test: `'  María García  '` → no leading/trailing spaces
  - File: `tests/Unit/Services/Normalization/NombreNormalizadorTest.php`
  - Acceptance: tests fail — service doesn't exist

### R2 (Collapse Spaces)

- [x] 2.2 GREEN: Create NombreNormalizador skeleton with `normalize()`
  - File: `app/Services/Normalization/NombreNormalizador.php`
  - Basic class with `normalize(string $name): NombreNormalizadoDTO`
  - Acceptance: R1 tests pass

- [x] 2.3 RED: Write failing tests for Rule R2 — collapse multiple spaces
  - Test: `'Juan   Pérez'` → single space between
  - Test: `'María\t\tGarcía'` → tabs collapsed to single space
  - Acceptance: tests fail — R2 not implemented

- [x] 2.4 GREEN: Implement R2 after R1 in pipeline
  - Replace `\s+` with single space
  - Acceptance: test 2.3 passes

### R3 (Academic Titles)

- [x] 2.5 RED: Write failing tests for Rule R3 — remove academic titles
  - Test: `'Dr. Juan Pérez'` → title stripped
  - Test: `'Dra. María García'` → title stripped with period
  - Test: `'Licdo. José López'` → Licdo. stripped
  - Test: `'Ing. Carlos Ruiz'` → Ing. stripped
  - Test: `'Prof. Ana Soto'` → Prof. stripped
  - Test: `'Jefe Juan Pérez'` → title NOT removed (not at start)
  - File: `tests/Unit/Services/Normalization/NombreNormalizadorTest.php`
  - Note: regex uses `/iu` flag for Unicode + case-insensitive
  - Acceptance: tests fail — R3 not implemented

- [x] 2.6 GREEN: Implement R3 using anchored regex after R2
  - Regex: `/^(?:dr\.?|dra\.?|lic\.?|licdo\.?|licda\.?|ing\.?|mg\.?|mtra\.?|mtro\.?|prof\.?|profa\.?)\s+/iu`
  - Acceptance: test 2.5 passes

### R4 (Courtesy Titles)

- [x] 2.7 RED: Write failing tests for Rule R4 — remove courtesy titles
  - Test: `'Sr. Juan Pérez'` → Sr. stripped
  - Test: `'Sra. María García'` → Sra. stripped
  - Test: `'Don José López'` → Don stripped (no period needed)
  - Test: `'Doña Carmen Ruiz'` → Doña stripped
  - Test: `'Ab. Pedro Gomez'` → Ab. stripped
  - Test: `'Juan Sr. Pérez'` → title NOT removed (not at start)
  - Acceptance: tests fail — R4 not implemented

- [x] 2.8 GREEN: Implement R4 using anchored regex after R3
  - Regex: `/^(?:sr\.?|sra\.?|srta\.?|ab\.?|abg\.?|don|doña)\s+/iu`
  - Acceptance: test 2.7 passes

### R5 (Title Case)

- [x] 2.9 RED: Write failing tests for Rule R5 — title case
  - Test: `'JUAN PÉREZ'` → `'Juan Pérez'`
  - Test: `'MARÍA GARCÍA'` → `'María García'`
  - Test: `'juan'` → `'Juan'`
  - Test: `'DRA. MARÍA'` → `'Dra. María'` (title removed, then title-cased)
  - Note: `mb_convert_case(MB_CASE_TITLE, 'UTF-8')` handles Spanish accents correctly
  - Acceptance: tests fail — R5 not implemented

- [x] 2.10 GREEN: Implement R5 using `mb_convert_case` after R4
  - Acceptance: test 2.9 passes

### R6 (Accent Stripping — matchingKey only)

- [x] 2.11 RED: Write failing tests for Rule R6 — accent stripping
  - Test: `'Juan Pérez'` → matchingKey is `'juan perez'` (accents stripped)
  - Test: `'María'` → matchingKey is `'maria'`
  - Test: `'ñ'}` → matchingKey is `'n'`
  - Test: `'ü'}` → matchingKey is `'u'`
  - Test: normalized property STILL has accents: `'María'`
  - Note: `strtr()` with explicit accent map (á→a, é→e, í→i, ó→o, ú→u, ñ→n, ü→u)
  - Acceptance: tests fail — R6 not implemented

- [x] 2.12 GREEN: Implement R6 — apply `strtr()` accent map to matchingKey only
  - Accent map: `['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','ñ'=>'n','Ñ'=>'n','ü'=>'u','Ü'=>'u']`
  - Acceptance: test 2.11 passes

### R7 (Trailing Punctuation)

- [x] 2.13 RED: Write failing tests for Rule R7 — remove trailing punctuation
  - Test: `'Juan Pérez.'` → normalized is `'Juan Pérez'`
  - Test: `'María García...'` → all trailing periods removed
  - Test: `'Juan Pérez:,;'` → all trailing punctuation removed
  - Note: R7 must run BEFORE R5 (Title Case) so period doesn't affect capitalization
  - Acceptance: tests fail — R7 not implemented

- [x] 2.14 GREEN: Implement R7 using regex `'/[.:;,]+$/'` after R4, before R5
  - Acceptance: test 2.13 passes

### Combined Pipeline & Edge Cases

- [x] 2.15 RED: Write failing tests for full pipeline (all rules)
  - Test: `'  Dr.   JUAN  PÉREZ.  '` → normalized `'Juan Pérez'`, matchingKey `'juan perez'`
  - Test: `'Dra. María DEL CARMEN Pérez...'` → normalized `'María Del Carmen Pérez'`
  - File: `tests/Unit/Services/Normalization/NombreNormalizadorTest.php`
  - Acceptance: tests fail — pipeline order wrong

- [x] 2.16 GREEN: Fix pipeline order to R1→R2→R3→R4→R7→R5→R6
  - Acceptance: test 2.15 passes

- [x] 2.17 RED: Write failing tests for edge cases
  - Test: `null` input → `normalize()` throws TypeError
  - Test: `null` input → `normalizeNullable()` returns `null`
  - Test: `''` (empty string) → `normalizeNullable()` returns `null`
  - Test: `'Juan'` (single word) → works correctly
  - Test: `'García-López'` → hyphen preserved in normalized, matchingKey `'garcia-lopez'`
  - Test: `"D'Elía"` → apostrophe preserved in normalized, matchingKey `"d'elia martinez"`
  - Test: Non-Spanish: `'Zhang Wei'` → passes through with accents intact
  - Acceptance: tests fail — edge cases not handled

- [x] 2.18 GREEN: Implement nullable wrapper and edge case handling
  - `normalize(string $name)` throws on null
  - `normalizeNullable(?string $name)` returns `null` for null/empty
  - Single word: works through pipeline
  - Hyphen: `mb_convert_case` handles hyphenated words correctly
  - Apostrophe: `mb_convert_case` handles correctly
  - Non-Spanish: accent map only strips defined chars, others pass through
  - Acceptance: test 2.17 passes

- [x] 2.19 RED: Write determinism test
  - Test: call `normalize('Dr. Juan Pérez')` 5 times
  - Assert: all 5 DTOs have identical `normalized` and `matchingKey`
  - Acceptance: tests fail — not verified

- [x] 2.20 GREEN: Verify determinism — all calls produce identical output
  - Acceptance: test 2.19 passes

- [x] 2.21 REFACTOR: Pint clean on NombreNormalizador
  - Command: `./vendor/bin/pint app/Services/Normalization/`
  - Acceptance: no violations

## Phase 3: Migrations

- [x] 3.1 Create migration for `resultados_scraping.gemini_nombre_normalizado`
  - File: `database/migrations/2026_04_11_000004_add_gemini_nombre_normalizado_to_resultados_scraping.php`
  - Column: `string('gemini_nombre_normalizado', 300)->nullable()->index()`
  - Place after `gemini_nombre` column
  - SQLite-compatible: use `string()` not PostgreSQL-specific types
  - Acceptance: migration runs on SQLite

- [x] 3.2 Create migration for `clasificaciones_feedback.corregido_nombre_normalizado`
  - File: `database/migrations/2026_04_11_000005_add_corregido_nombre_normalizado_to_clasificaciones_feedback.php`
  - Column: `string('corregido_nombre_normalizado', 300)->nullable()->index()`
  - Place after `corregido_nombre` column
  - SQLite-compatible
  - Acceptance: migration runs on SQLite

- [x] 3.3 Run migrations
  - Command: `php artisan migrate`
  - Acceptance: both columns exist with indexes

## Phase 4: Model Updates

- [x] 4.1 Add `gemini_nombre_normalizado` to ResultadoScraping $fillable
  - File: `app/Models/ResultadoScraping.php`
  - Add `'gemini_nombre_normalizado'` to $fillable array
  - Acceptance: model accepts mass assignment

- [x] 4.2 Add `corregido_nombre_normalizado` to ClasificacionFeedback $fillable
  - File: `app/Models/ClasificacionFeedback.php`
  - Add `'corregido_nombre_normalizado'` to $fillable array
  - Acceptance: model accepts mass assignment

## Phase 5: GeminiFiltroService Integration (TDD)

- [x] 5.1 RED: Write failing test for GeminiFiltroService normalization on persist
  - Test: `persistirResultado()` creates record with `gemini_nombre_normalizado` populated
  - Test: `'Dr. Juan Pérez'` → `gemini_nombre_normalizado` = `'Juan Pérez'`
  - File: `tests/Feature/Services/GeminiFiltroNormalizacionTest.php`
  - Note: Use in-memory SQLite, factory for ResultadoScraping
  - Acceptance: tests fail — integration not implemented

- [x] 5.2 RED: Write failing test for null name propagation
  - Test: Gemini returns `nombre=null` → `gemini_nombre_normalizado` also null
  - Acceptance: tests fail — null not handled

- [x] 5.3 RED: Write failing test for graceful failure
  - Test: NombreNormalizador throws → persistence continues with `gemini_nombre_normalizado=null`
  - Test: Warning is logged
  - Note: Use `Log::fake()` to verify warning logged
  - Acceptance: tests fail — try/catch not implemented

- [x] 5.4 GREEN: Implement GeminiFiltroService integration
  - File: `app/Services/Gemini/GeminiFiltroService.php`
  - Inject `NombreNormalizador` via constructor
  - In `persistirResultado()`: call `$this->normalizador->normalizeNullable($dto->nombre)`
  - Wrap in try/catch: on Throwable → `Log::warning(...)` and `$norm = null`
  - Pass normalized value to create/update
  - Acceptance: tests 5.1–5.3 pass

## Phase 6: Resultados Livewire Integration (TDD)

- [x] 6.1 RED: Write failing test for Resultados feedback normalization
  - Test: `guardarFeedbackIncorrecto()` populates `corregido_nombre_normalizado`
  - Test: `'Dr. Juan Pérez'` → `corregido_nombre_normalizado` = `'Juan Pérez'`
  - File: `tests/Feature/Livewire/ResultadosFeedbackNormalizacionTest.php`
  - Note: Use `Livewire::test()` syntax
  - Acceptance: tests fail — integration not implemented

- [x] 6.2 RED: Write failing test for empty feedback name
  - Test: `feedbackNombreCorregido` is null/empty → `corregido_nombre_normalizado` is null
  - Acceptance: tests fail — empty not handled

- [x] 6.3 RED: Write failing test for upsert preserves normalized
  - Test: existing record updated with new name → normalized updated too
  - Acceptance: tests fail — upsert doesn't include normalized

- [x] 6.4 GREEN: Implement Resultados normalization integration
  - File: `app/Livewire/Scraper/Resultados.php`
  - In `guardarFeedbackIncorrecto()`: resolve `NombreNormalizador` via `app()`
  - Call `$normalizador->normalizeNullable($this->feedbackNombreCorregido)`
  - Pass to `updateOrCreate` alongside `corregido_nombre`
  - Acceptance: tests 6.1–6.3 pass

## Phase 7: Backfill Command (TDD)

- [x] 7.1 RED: Write failing test for command existence and signature
  - Test: `artisan('simo:normalizar-nombres')` resolves without error
  - Test: command has `--chunk`, `--dry-run`, `--force` options
  - File: `tests/Feature/Commands/NormalizarNombresCommandTest.php`
  - Acceptance: tests fail — command doesn't exist

- [x] 7.2 GREEN: Create NormalizarNombresCommand skeleton
  - File: `app/Console/Commands/NormalizarNombresCommand.php`
  - Signature: `simo:normalizar-nombres {--chunk=500} {--dry-run} {--force}`
  - Extends `Command`
  - Acceptance: test 7.1 passes

- [x] 7.3 RED: Write failing test for `--dry-run` mode
  - Test: `--dry-run` outputs count without writing to DB
  - Test: record count is accurate
  - Acceptance: tests fail — dry-run not implemented

- [x] 7.4 RED: Write failing test for `--force` skips prompt
  - Test: `--force` begins processing immediately (no prompt)
  - Acceptance: tests fail — force not implemented

- [x] 7.5 GREEN: Implement backfill logic for resultados_scraping
  - Query: `ResultadoScraping::whereNotNull('gemini_nombre')->whereNull('gemini_nombre_normalizado')`
  - Use `chunkById` for pagination (not `chunk`)
  - Process each: normalize, update
  - Acceptance: tests 7.3–7.4 pass for resultados_scraping

- [x] 7.6 RED: Write failing test for backfill processing both tables
  - Test: both `resultados_scraping` AND `clasificaciones_feedback` get processed
  - Test: each table reports its own count
  - Acceptance: tests fail — only one table processed

- [x] 7.7 GREEN: Implement backfill for clasificaciones_feedback
  - Query: `ClasificacionFeedback::whereNotNull('corregido_nombre')->whereNull('corregido_nombre_normalizado')`
  - Same `chunkById` pattern
  - Acceptance: test 7.6 passes

- [x] 7.8 RED: Write failing test for idempotency
  - Test: run backfill twice → second run reports 0 updates
  - Test: query filters by IS NULL so already-normalized skip
  - Acceptance: tests fail — idempotency not verified

- [x] 7.9 GREEN: Verify idempotency — WHERE NULL filter ensures 2nd run = 0
  - Acceptance: test 7.8 passes

- [x] 7.10 RED: Write failing test for `--chunk=N` option
  - Test: `--chunk=100` processes in batches of 100
  - Use `withChunkCount` or similar to verify chunk size
  - Acceptance: tests fail — chunk option not implemented

- [x] 7.11 GREEN: Implement chunk option — uses `$this->option('chunk')` value
  - Pass to `chunkById` calls
  - Acceptance: test 7.10 passes

- [x] 7.12 RED: Write failing test for error resilience
  - Test: one record throws exception → backfill continues
  - Test: error is logged
  - Test: remaining records process successfully
  - Note: Use try/catch per record, log errors
  - Acceptance: tests fail — error stops backfill

- [x] 7.13 GREEN: Implement error resilience — try/catch per record, log and continue
  - Wrap normalization+update in try/catch per record
  - On error: `Log::error(...)`, continue to next
  - Acceptance: test 7.12 passes

- [x] 7.14 REFACTOR: Add progress bar and completion summary
  - `$this->output->progressStart($count)`
  - `$this->output->progressFinish()`
  - Output: `"Backfill complete. N records updated."`
  - Acceptance: output format correct

## Phase 8: Integration Tests

- [x] 8.1 Write integration test: Gemini analyze → persist → normalized column populated
  - File: `tests/Feature/Services/GeminiFiltroNormalizacionTest.php`
  - Full flow: call `persistirResultado()` with known name
  - Assert `gemini_nombre_normalizado` is correct
  - Acceptance: test passes

- [x] 8.2 Write integration test: User submits feedback → normalized column populated
  - File: `tests/Feature/Livewire/ResultadosFeedbackNormalizacionTest.php`
  - Full flow: submit feedback with corrected name
  - Assert `corregido_nombre_normalizado` is correct
  - Acceptance: test passes

- [x] 8.3 Write integration test: Backfill existing records → both tables updated
  - File: `tests/Feature/Commands/NormalizarNombresCommandTest.php`
  - Create records with names but NULL normalized
  - Run command with `--force`
  - Assert both tables updated correctly
  - Acceptance: test passes

## Phase 9: Verification

- [x] 9.1 Run targeted unit tests for NombreNormalizador
  - Command: `php artisan test tests/Unit/Services/Normalization/`
  - Acceptance: all pass (20+ cases)

- [x] 9.2 Run targeted tests for DTO
  - Command: `php artisan test tests/Unit/Services/Normalization/NombreNormalizadoDTOTest.php`
  - Acceptance: all pass

- [x] 9.3 Run integration tests for GeminiFiltroService
  - Command: `php artisan test tests/Feature/Services/GeminiFiltroNormalizacionTest.php`
  - Acceptance: all pass

- [x] 9.4 Run integration tests for Livewire component
  - Command: `php artisan test tests/Feature/Livewire/ResultadosFeedbackNormalizacionTest.php`
  - Acceptance: all pass

- [x] 9.5 Run integration tests for backfill command
  - Command: `php artisan test tests/Feature/Commands/NormalizarNombresCommandTest.php`
  - Acceptance: all pass

- [x] 9.6 Run Pint on modified files
  - Command: `./vendor/bin/pint app/Services/Normalization/ app/Services/Gemini/GeminiFiltroService.php app/Livewire/Scraper/Resultados.php app/Models/ResultadoScraping.php app/Models/ClasificacionFeedback.php app/Console/Commands/NormalizarNombresCommand.php`
  - Acceptance: no violations

- [x] 9.7 Run full test suite
  - Command: `php artisan test`
  - Acceptance: 316+ tests pass, no regressions

- [x] 9.8 Verify migrations run on SQLite (test environment)
  - Command: `php artisan migrate:fresh --seed` (if using in-memory)
  - Acceptance: both migrations apply cleanly
