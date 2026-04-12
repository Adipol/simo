# Spec: normalizacion-nombres

## Capability: name-normalization

### Purpose

Pure normalization service that transforms raw name strings into three canonical forms: original (preserved), normalized (display-ready), and matchingKey (deduplication identifier).

### Requirements

#### REQ-1: Service Purity
The `NombreNormalizador` service **MUST** be pure — it **MUST NOT** access the database, perform I/O operations, or rely on static mutable state.

#### REQ-2: Deterministic Output
The method `normalize(string $name): NombreNormalizadoDTO` **MUST** produce identical output for identical input on every invocation.

#### REQ-3: DTO Structure
The `NombreNormalizadoDTO` **MUST** be declared as `final readonly class` and contain exactly three public readonly properties:
- `original: string` — the input value preserved verbatim
- `normalized: string` — the display form after rule application
- `matchingKey: string` — the deduplication key (lowercase, accent-stripped)

#### REQ-4: Matching Key Normalization
The `matchingKey` property **MUST** be:
- Converted to lowercase (all characters)
- Stripped of accents: á→a, é→e, í→i, ó→o, ú→u, ñ→n, ü→u

#### REQ-5: Display Form Preservation
The `normalized` property **MUST**:
- Preserve all original accents (á, é, í, ó, ú, ñ, ü)
- Be formatted in Title Case (first letter of each word uppercase, remaining lowercase)

#### REQ-6: Rule R1 — Trim Whitespace
The service **MUST** remove leading and trailing whitespace characters from the input before any other processing.

#### REQ-7: Rule R2 — Collapse Spaces
The service **MUST** replace multiple consecutive whitespace characters with a single space.

#### REQ-8: Rule R3 — Remove Academic Titles
The service **MUST** remove the following academic titles when they appear at the start of the name (case-insensitive, with or without trailing period): Dr., Dra., Lic., Licdo., Licda., Ing., Mg., Mtra., Mtro., Prof., Profa.

#### REQ-9: Rule R4 — Remove Courtesy Titles
The service **MUST** remove the following courtesy titles when they appear at the start of the name (case-insensitive, with or without trailing period): Sr., Sra., Srta., Ab., Abg., Don, Doña.

#### REQ-10: Rule R5 — Title Case
After title removal, the service **MUST** convert the name to Title Case (each word capitalized).

#### REQ-11: Rule R6 — Accent Stripping (matchingKey only)
The service **MUST** apply accent stripping ONLY to the `matchingKey` property and **MUST NOT** modify the `normalized` property.

#### REQ-12: Rule R7 — Remove Trailing Punctuation
The service **MUST** remove trailing punctuation characters (.,;:). Multiple trailing punctuation marks **MUST** all be removed.

#### REQ-13: Title Position Constraint
Title removal rules (R3, R4) **MUST** only apply when the title appears at the beginning of the name. Titles in the middle or end **MUST NOT** be removed.

#### REQ-14: Nullable Input Handling
The method `normalizeNullable(?string $name): ?NombreNormalizadoDTO` **MUST** return `null` when the input is `null` or an empty string (`''`).

#### REQ-15: Single-Word Name Support
The service **MUST** correctly handle single-word names without errors.

#### REQ-16: Hyphenated Name Support
The service **MUST** preserve hyphens within names and handle hyphenated surnames correctly.

#### REQ-17: Apostrophe Name Support
The service **MUST** preserve apostrophes within names and handle names like "D'Elía" correctly.

#### REQ-18: Initials Expansion — Out of Scope
The service **MUST NOT** attempt to expand initials (e.g., "J." → "Juan"). This functionality is explicitly out of scope for v1.

#### REQ-19: Phonetic Matching — Out of Scope
The service **MUST NOT** implement phonetic matching algorithms (Soundex, Metaphone). This functionality is explicitly out of scope for v1.

### Scenarios

#### Scenario: Basic academic title removal
- **GIVEN** the input string `"Dr. Juan Pérez"`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `original="Dr. Juan Pérez"`, `normalized="Juan Pérez"`, `matchingKey="juan perez"`

#### Scenario: Courtesy title removal with accent preservation
- **GIVEN** the input string `"Sra. MARÍA GARCÍA"`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="María García"`, `matchingKey="maria garcia"`

#### Scenario: Whitespace normalization
- **GIVEN** the input string `"  Juan   Pérez  "`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="Juan Pérez"`, `matchingKey="juan perez"`

#### Scenario: Trailing punctuation removal
- **GIVEN** the input string `"Juan Pérez."`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="Juan Pérez"` (period removed)

#### Scenario: Nullable method with null input
- **GIVEN** the input value `null`
- **WHEN** `normalizeNullable()` is invoked
- **THEN** the method returns `null`

#### Scenario: Nullable method with empty string
- **GIVEN** the input string `""`
- **WHEN** `normalizeNullable()` is invoked
- **THEN** the method returns `null`

#### Scenario: Single-word name
- **GIVEN** the input string `"Pérez"`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="Pérez"`, `matchingKey="perez"`

#### Scenario: Multiple title removal with particle preservation
- **GIVEN** the input string `"Dra. María del Carmen Pérez"`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="María Del Carmen Pérez"` (titles removed, "del" preserved and capitalized)

#### Scenario: Title in middle not removed
- **GIVEN** the input string `"Juan Dr. Pérez"`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="Juan Dr. Pérez"` (title NOT removed, not at start)

#### Scenario: Hyphenated surname preservation
- **GIVEN** the input string `"García-López"`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="García-López"`, `matchingKey="garcia-lopez"`

#### Scenario: Apostrophe name preservation
- **GIVEN** the input string `"D'Elía Martínez"`
- **WHEN** `normalize()` is invoked
- **THEN** the DTO contains `normalized="D'Elía Martínez"`, `matchingKey="d'elia martinez"`

#### Scenario: Deterministic output
- **GIVEN** the input string `"Dr. Juan Pérez"`
- **WHEN** `normalize()` is invoked twice with the same input
- **THEN** both invocations produce DTOs with identical property values

#### Scenario: Different names produce different keys
- **GIVEN** the input strings `"Juan Pérez"` and `"María García"`
- **WHEN** `normalize()` is invoked for both
- **THEN** the resulting `matchingKey` values are different

#### Scenario: Name variations produce same key
- **GIVEN** the input strings `"Dr. Juan Pérez"` and `"JUAN PÉREZ"` and `"juan perez"`
- **WHEN** `normalize()` is invoked for all three
- **THEN** all three DTOs have the same `matchingKey="juan perez"`

---

## Capability: scraping-data-pipeline

### Purpose

Data pipeline that persists PEP detection results from Gemini, now enhanced to store normalized name forms alongside raw values.

### Requirements

#### REQ-1: Normalization on Persist
When `GeminiFiltroService::persistirResultado()` creates a new `ResultadoScraping` record, it **MUST** normalize the `gemini_nombre` value and store the result in the `gemini_nombre_normalizado` column.

#### REQ-2: Null Propagation
If the `gemini_nombre` value is `null`, the `gemini_nombre_normalizado` column **MUST** also be set to `null`.

#### REQ-3: Original Preservation
The original `gemini_nombre` value **MUST** remain unchanged and **MUST** contain the raw value exactly as received from Gemini.

#### REQ-4: Single Write Operation
The normalization and persistence **MUST** occur within a single database write operation. Two separate updates to set the normalized value **MUST NOT** occur.

#### REQ-5: Graceful Degradation
If name normalization fails (throws exception), the persistence **MUST** continue with `gemini_nombre_normalizado` set to `null`. The failure **MUST** be logged as a warning, and the entire persistence operation **MUST NOT** be aborted.

### Scenarios

#### Scenario: Normal name with title persisted
- **GIVEN** Gemini returns a result with `nombre="Dr. Juan Pérez"`
- **WHEN** `persistirResultado()` is invoked
- **THEN** the created record has `gemini_nombre="Dr. Juan Pérez"` AND `gemini_nombre_normalizado="Juan Pérez"`

#### Scenario: Null name propagation
- **GIVEN** Gemini returns a result with `nombre=null`
- **WHEN** `persistirResultado()` is invoked
- **THEN** the created record has `gemini_nombre=null` AND `gemini_nombre_normalizado=null`

#### Scenario: Variations produce same normalized form
- **GIVEN** Two separate results with `nombre="Dr. Juan Pérez"` and `nombre="JUAN PÉREZ"`
- **WHEN** Both are persisted
- **THEN** Both records have the same `gemini_nombre_normalizado="Juan Pérez"` (display form)

---

## Capability: feedback-classification

### Purpose

Feedback system for correcting misclassified PEP results, now enhanced to store normalized forms of user-provided name corrections.

### Requirements

#### REQ-1: Correction Normalization
When `Resultados::guardarFeedbackIncorrecto()` saves feedback with `feedbackNombreCorregido` set, it **MUST** normalize the value and store it in the `corregido_nombre_normalizado` column.

#### REQ-2: User Input Preservation
The original `corregido_nombre` value **MUST** remain unchanged and **MUST** contain the user's input exactly as submitted.

#### REQ-3: Empty Correction Handling
If `feedbackNombreCorregido` is `null` or an empty string, the `corregido_nombre_normalizado` column **MUST** be set to `null`.

#### REQ-4: Upsert Integration
The upsert logic that updates existing feedback records **MUST** include the `corregido_nombre_normalizado` column in the update operation.

### Scenarios

#### Scenario: Name correction with title
- **GIVEN** A user submits feedback with `feedbackNombreCorregido="Dr. Juan Pérez"`
- **WHEN** `guardarFeedbackIncorrecto()` is invoked
- **THEN** the feedback record has `corregido_nombre="Dr. Juan Pérez"` AND `corregido_nombre_normalizado="Juan Pérez"`

#### Scenario: Empty name correction
- **GIVEN** A user submits feedback with `feedbackNombreCorregido=null` (blank field)
- **WHEN** `guardarFeedbackIncorrecto()` is invoked
- **THEN** the feedback record has `corregido_nombre=null` AND `corregido_nombre_normalizado=null`

#### Scenario: Update existing feedback with new name
- **GIVEN** An existing feedback record with `corregido_nombre="Old Name"` and `corregido_nombre_normalizado="Old Name"`
- **WHEN** The user updates the feedback with `feedbackNombreCorregido="New Name"`
- **THEN** the record is updated with `corregido_nombre="New Name"` AND `corregido_nombre_normalizado="New Name"`

---

## Backfill Command Requirements

### Purpose

Artisan command to populate normalized columns for existing records that were created before the normalization service was deployed.

### Requirements

#### REQ-BF-1: Selective Processing
The command **MUST** only process records where `gemini_nombre IS NOT NULL AND gemini_nombre_normalizado IS NULL`.

#### REQ-BF-2: Chunked Processing
The command **MUST** process records in chunks. The default chunk size **MUST** be 500 records.

#### REQ-BF-3: Chunk Option
The command **MUST** accept a `--chunk=N` option to override the default chunk size.

#### REQ-BF-4: Dry-Run Mode
The command **MUST** accept a `--dry-run` option. In this mode, **NO** database writes **MUST** occur.

#### REQ-BF-5: Dry-Run Output
In `--dry-run` mode, the command **MUST** output the count of records that would be updated.

#### REQ-BF-6: Confirmation Prompt
Without the `--force` option, the command **MUST** prompt the user with: `"This will update N records. Continue? [y/N]"` before processing.

#### REQ-BF-7: Force Option
The command **MUST** accept a `--force` option to skip the confirmation prompt.

#### REQ-BF-8: Progress Display
The command **MUST** display a progress bar during record processing.

#### REQ-BF-9: Idempotency
The command **MUST** be idempotent — running the command twice on the same database state **MUST** result in zero updates on the second run.

#### REQ-BF-10: Completion Summary
On completion, the command **MUST** output: `"Backfill complete. N records updated."`

#### REQ-BF-11: Multi-Table Support
The command **MUST** handle backfill for both `resultados_scraping.gemini_nombre_normalizado` AND `clasificaciones_feedback.corregido_nombre_normalizado`.

#### REQ-BF-12: Error Resilience
Errors on individual records **MUST NOT** stop the entire backfill process. Errors **MUST** be logged, and processing **MUST** continue with the next record.

### Scenarios

#### Scenario: First run processes all records
- **GIVEN** 1000 records with `gemini_nombre IS NOT NULL` and `gemini_nombre_normalizado IS NULL`
- **WHEN** the command is executed with `--force`
- **THEN** all 1000 records are updated, and the output shows `"Backfill complete. 1000 records updated."`

#### Scenario: Second run is idempotent
- **GIVEN** The backfill was already run and all records have `gemini_nombre_normalizado` set
- **WHEN** the command is executed again
- **THEN** 0 records are processed, and the output shows `"Backfill complete. 0 records updated."`

#### Scenario: Dry-run shows preview
- **GIVEN** 1000 records need normalization
- **WHEN** the command is executed with `--dry-run`
- **THEN** no database writes occur, and the output shows `"Would update 1000 records"`

#### Scenario: Custom chunk size
- **GIVEN** 1000 records need normalization
- **WHEN** the command is executed with `--chunk=100 --force`
- **THEN** records are processed in batches of 100

#### Scenario: Force skips prompt
- **GIVEN** 500 records need normalization
- **WHEN** the command is executed with `--force`
- **THEN** processing begins immediately without user confirmation

#### Scenario: Without force shows prompt
- **GIVEN** 500 records need normalization
- **WHEN** the command is executed without `--force`
- **THEN** the prompt `"This will update 500 records. Continue? [y/N]"` is displayed

#### Scenario: Individual record error handling
- **GIVEN** 1000 records need normalization, and record #500 causes an exception
- **WHEN** the command is executed with `--force`
- **THEN** the error is logged, processing continues, and 999 records are updated successfully

---

## Non-Functional Requirements

### Performance

#### NF-PERF-1: Normalization Speed
The `NombreNormalizador::normalize()` method **MUST** complete in less than 1ms for typical names up to 50 characters.

#### NF-PERF-2: Backfill Throughput
The backfill command **MUST** process at least 500 records per second on average.

#### NF-PERF-3: Memory Efficiency
The normalization service **MUST NOT** allocate large temporary buffers. Memory usage **SHOULD** remain constant regardless of input size.

### Determinism

#### NF-DET-1: Consistent Output
The same input string **MUST** produce identical output across all invocations, regardless of system state or time.

#### NF-DET-2: Fixed Rule Order
Rules **MUST** be applied in the fixed order: R1 → R2 → R3 → R4 → R5 → R7 → R6.

### Data Integrity

#### NF-INT-1: Original Column Immutability
Original columns (`gemini_nombre`, `corregido_nombre`) **MUST NEVER** be modified by this change.

#### NF-INT-2: No Data Truncation
The backfill process **MUST NOT** truncate or lose data during normalization.

#### NF-INT-3: Index Maintenance
Indexes on normalized columns **MUST** be maintained automatically by the database.

### Portability

#### NF-PORT-1: Database Compatibility
Migration syntax **MUST** work on both SQLite (test environment) and PostgreSQL (production).

#### NF-PORT-2: Platform Independence
The normalization service **MUST NOT** use any database-specific functions or features.

### Security

#### NF-SEC-1: Input Sanitization
User input **MUST** be handled via Eloquent's `$fillable` mechanism. Direct query injection **MUST NOT** occur.

#### NF-SEC-2: Script Safety
Normalization **MUST NOT** execute or evaluate any HTML, JavaScript, or script content from input strings.

### Internationalization

#### NF-I18N-1: UTF-8 Preservation
UTF-8 characters **MUST** be preserved throughout processing, including: ñ, á, é, í, ó, ú, ü.

#### NF-I18N-2: Non-Spanish Character Pass-Through
Non-Spanish characters (Cyrillic, Chinese, Arabic, etc.) **MUST** pass through normalization without errors, even if not specially handled.
