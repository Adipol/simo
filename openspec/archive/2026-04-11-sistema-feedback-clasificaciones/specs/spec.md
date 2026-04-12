# Delta Spec: Sistema Feedback Clasificaciones

## Change: `sistema-feedback-clasificaciones`

---

## Capability: `classification-feedback` (NEW)

### Purpose
Sistema para que usuarios autorizados registren feedback sobre clasificaciones Gemini (PEP/OPI/NO_REL) en resultados de scraping. Permite capturar correcciones y mejorar la calidad del modelo de clasificación.

### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| **REQ-1** | System MUST store user feedback per `(resultado_scraping_id, usuario_id)` tuple | MUST |
| **REQ-2** | Each user MUST have at most ONE feedback per resultado (composite PK constraint) | MUST |
| **REQ-3** | Feedback MUST support two types: `correcto` and `incorrecto` | MUST |
| **REQ-4** | System MUST capture a snapshot of the Gemini classification at the time of feedback | MUST |
| **REQ-5** | "Incorrecto" feedback MUST include a `motivo` (required text, min 10 chars) | MUST |
| **REQ-6** | "Incorrecto" feedback MUST include a `corregido_categoria` (enum: PEP, OPI, NO_REL) | MUST |
| **REQ-7** | "Incorrecto" feedback MAY include optional correction fields: `corregido_is_pep`, `corregido_nombre`, `corregido_cargo` | MAY |
| **REQ-8** | System MUST support upsert — updating feedback replaces the previous value, not appends | MUST |
| **REQ-9** | Deleting a ResultadoScraping MUST cascade delete its feedback | MUST |
| **REQ-10** | Model MUST expose query scopes: `correctos()`, `incorrectos()`, `porUsuario($userId)`, `porResultado($resultadoId)` | MUST |
| **REQ-11** | Feedback MUST record `updated_at` on every modification | MUST |

### Scenarios

#### Scenario: Creating first feedback (correcto)

- **GIVEN** a resultado_scraping with `gemini_analyzed = true`
- **AND** an authenticated user with `dar feedback clasificaciones` permission
- **WHEN** the user marks the classification as "correcto"
- **THEN** a new record is created with `tipo = 'correcto'`
- **AND** `clasificacion_snapshot` contains the current Gemini classification
- **AND** `created_at` and `updated_at` are set to current timestamp

#### Scenario: Creating first feedback (incorrecto) with motivo

- **GIVEN** a resultado_scraping with `gemini_analyzed = true`
- **AND** an authenticated user with `dar feedback clasificaciones` permission
- **WHEN** the user marks the classification as "incorrecto" with motivo and categoria
- **THEN** a new record is created with `tipo = 'incorrecto'`
- **AND** `motivo` and `corregido_categoria` are stored
- **AND** `clasificacion_snapshot` contains the current Gemini classification

#### Scenario: Updating existing feedback (correcto → incorrecto)

- **GIVEN** an existing feedback record with `tipo = 'correcto'`
- **WHEN** the same user changes their feedback to "incorrecto" with motivo
- **THEN** the existing record is updated (not duplicated)
- **AND** `tipo` changes to `incorrecto`
- **AND** `updated_at` reflects the modification time
- **AND** `corregido_categoria` and `motivo` are populated

#### Scenario: Attempting to create feedback without motivo when type=incorrecto → validation fails

- **GIVEN** a user attempts to submit "incorrecto" feedback
- **WHEN** the `motivo` field is empty or has less than 10 characters
- **THEN** validation fails with error message "El motivo es requerido y debe tener al menos 10 caracteres"
- **AND** no record is created or updated

#### Scenario: Attempting to create feedback without categoria when type=incorrecto → validation fails

- **GIVEN** a user attempts to submit "incorrecto" feedback
- **WHEN** the `corregido_categoria` field is not selected
- **THEN** validation fails with error message "La categoría corregida es requerida"
- **AND** no record is created or updated

#### Scenario: Querying feedbacks by user

- **GIVEN** multiple feedback records exist from different users
- **WHEN** calling `ClasificacionFeedback::porUsuario($userId)->get()`
- **THEN** only feedback records for that specific user are returned

#### Scenario: Querying feedbacks by resultado

- **GIVEN** a resultado_scraping with multiple feedback records from different users
- **WHEN** calling `ClasificacionFeedback::porResultado($resultadoId)->get()`
- **THEN** all feedback records for that resultado are returned

#### Scenario: Deleting ResultadoScraping cascades feedback

- **GIVEN** a resultado_scraping with associated feedback records
- **WHEN** the resultado_scraping is deleted
- **THEN** all associated feedback records are automatically deleted (CASCADE)

#### Scenario: Snapshot captures the classification correctly

- **GIVEN** a resultado_scraping with `gemini_is_pep`, `gemini_categoria`, `gemini_confianza`, `gemini_nombre`, `gemini_cargo`
- **WHEN** feedback is created
- **THEN** `clasificacion_snapshot` JSON contains: `is_pep`, `categoria`, `confianza`, `nombre`, `cargo`
- **AND** values match the Gemini classification at the moment of feedback

---

## Capability: `scraper-resultados-ui` (MODIFIED)

### Purpose
Extensión del listado de resultados de scraping para incluir acciones de feedback directamente en las filas analizadas por Gemini.

### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| **REQ-1** | Rows where `gemini_analyzed = true` MUST display feedback buttons | MUST |
| **REQ-2** | Rows where `gemini_analyzed = false` MUST NOT display feedback buttons | MUST |
| **REQ-3** | Users without `dar feedback clasificaciones` permission MUST NOT see feedback buttons | MUST |
| **REQ-4** | Users with the permission MUST see "✅ Correcto" and "❌ Incorrecto" buttons per qualifying row | MUST |
| **REQ-5** | Clicking "Correcto" MUST save feedback immediately (no modal) | MUST |
| **REQ-6** | Clicking "Correcto" MUST show a success flash message | MUST |
| **REQ-7** | Clicking "Incorrecto" MUST open the feedback modal | MUST |
| **REQ-8** | Feedback modal MUST display the current Gemini classification (read-only) | MUST |
| **REQ-9** | Feedback modal MUST have form fields: categoria (required dropdown), nombre (optional), cargo (optional), motivo (required textarea) | MUST |
| **REQ-10** | Saving invalid form (missing motivo or categoria) MUST show validation errors inline | MUST |
| **REQ-11** | Saving valid form MUST close modal and update the row's visual state | MUST |
| **REQ-12** | Rows with existing feedback MUST display a visual indicator (correct/incorrect badge) | MUST |
| **REQ-13** | Clicking a button on a row with existing feedback MUST pre-fill the modal (if opening) or toggle state | MUST |
| **REQ-14** | Changing feedback from correct to incorrect (or vice versa) MUST update (not duplicate) | MUST |
| **REQ-15** | Feedback actions MUST NOT require a page reload (Livewire reactivity) | MUST |

### Scenarios

#### Scenario: Analyst sees feedback buttons on analyzed rows only

- **GIVEN** a list of resultados_scraping with mixed `gemini_analyzed` states
- **AND** an authenticated user with `dar feedback clasificaciones` permission
- **WHEN** the user views the Resultados listing
- **THEN** rows with `gemini_analyzed = true` show feedback buttons
- **AND** rows with `gemini_analyzed = false` do not show feedback buttons

#### Scenario: Operador does not see feedback buttons (permission denied)

- **GIVEN** an authenticated user with `operador` role (no `dar feedback clasificaciones` permission)
- **WHEN** the user views the Resultados listing
- **THEN** no feedback buttons are visible on any row
- **AND** attempting to call feedback actions directly returns 403

#### Scenario: Clicking "Correcto" on a fresh row

- **GIVEN** a row with no existing feedback
- **WHEN** the user clicks "✅ Correcto"
- **THEN** feedback is saved immediately with `tipo = 'correcto'`
- **AND** a success flash message appears
- **AND** the row displays a green checkmark indicator

#### Scenario: Clicking "Incorrecto" opens modal

- **GIVEN** a row with no existing feedback
- **WHEN** the user clicks "❌ Incorrecto"
- **THEN** the feedback modal opens
- **AND** the modal displays the current Gemini classification (read-only)
- **AND** all form fields are empty

#### Scenario: Submitting incorrect feedback without motivo fails

- **GIVEN** the feedback modal is open for "incorrecto" feedback
- **WHEN** the user selects a categoria but leaves motivo empty
- **AND** clicks "Guardar"
- **THEN** validation error appears for the motivo field
- **AND** the modal remains open
- **AND** no feedback is saved

#### Scenario: Submitting valid incorrect feedback updates row

- **GIVEN** the feedback modal is open with all required fields filled
- **WHEN** the user clicks "Guardar"
- **THEN** feedback is saved with `tipo = 'incorrecto'`
- **AND** the modal closes
- **AND** the row displays an orange warning indicator
- **AND** a success flash message appears

#### Scenario: Changing from correct to incorrect updates existing record

- **GIVEN** a row where the user previously marked "correcto"
- **AND** the row shows a green checkmark
- **WHEN** the user clicks "❌ Incorrecto" and submits the form
- **THEN** the existing feedback record is updated to `tipo = 'incorrecto'`
- **AND** the row changes to an orange warning indicator
- **AND** no duplicate record is created

#### Scenario: Row badge reflects feedback state

- **GIVEN** three rows: one without feedback, one with `correcto` feedback, one with `incorrecto` feedback
- **WHEN** the user views the Resultados listing
- **THEN** the row without feedback has no special badge
- **AND** the row with `correcto` shows a green checkmark badge
- **AND** the row with `incorrecto` shows an orange warning badge

---

## Non-Functional Requirements

### Performance

| Requirement | Priority |
|-------------|----------|
| Paginated queries MUST eager load only the current user's feedback (not all users) | MUST |
| Queries MUST use indexes on `(resultado_scraping_id, usuario_id)` and `tipo` | MUST |
| Feedback modal data MUST load via computed property (lazy) not eager loaded in main query | SHOULD |

### Data Integrity

| Requirement | Priority |
|-------------|----------|
| Unique constraint on `(resultado_scraping_id, usuario_id)` at DB level | MUST |
| FK with CASCADE on `resultado_scraping_id` delete | MUST |
| FK with RESTRICT on `usuario_id` delete (prevent user deletion with feedback) | MUST |
| `tipo` field MUST be enum/constraint with values `correcto`, `incorrecto` | MUST |
| `corregido_categoria` MUST be enum/constraint with values `PEP`, `OPI`, `NO_REL` when not null | MUST |

### Backward Compatibility

| Requirement | Priority |
|-------------|----------|
| Existing Resultados listing MUST continue to work for users without the feedback permission | MUST |
| Adding the new columns MUST NOT break existing tests | MUST |
| Default state for rows without feedback MUST be visually neutral | MUST |

### Security

| Requirement | Priority |
|-------------|----------|
| Permission check MUST be enforced at Livewire action level (not just UI) | MUST |
| The user recorded in `usuario_id` MUST be the authenticated user (never accept from request) | MUST |
| Feedback endpoint MUST validate `resultado_scraping_id` belongs to accessible scope | MUST |
| Rate limiting SHOULD be applied to feedback actions (prevent spam) | SHOULD |

---

## Summary

| Capability | Type | Requirements | Scenarios |
|------------|------|--------------|-----------|
| `classification-feedback` | NEW | 11 MUST, 1 MAY | 9 scenarios |
| `scraper-resultados-ui` | MODIFIED | 15 MUST | 8 scenarios |
| **Total** | — | **26 MUST, 1 MAY** | **17 scenarios** |

---

## Next Step

Ready for **sdd-design** phase. Design should cover:
1. Database schema (migration)
2. Eloquent model with composite PK configuration
3. Livewire component state management (`feedbackModalId`)
4. Blade UI components (buttons + modal)
