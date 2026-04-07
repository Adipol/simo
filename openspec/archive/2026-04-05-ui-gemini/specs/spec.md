# Delta Spec: UI Gemini Integration

## Status: IMPLEMENTED

## Requirements

### REQ-001: Gemini Filter in Resultados
**Given** a user viewing `/scraper/resultados`
**When** they select a Gemini filter option
**Then** the results are filtered by Gemini analysis status

| Filter Value | Behavior |
|--------------|----------|
| `''` (empty) | Show all results |
| `'pending'` | Show only `gemini_analyzed = false` |
| `'pep'` | Show only `gemini_analyzed = true` AND `gemini_is_pep = true` AND `gemini_categoria = 'PEP'` |
| `'opi'` | Show only `gemini_analyzed = true` AND `gemini_is_pep = true` AND `gemini_categoria = 'OPI'` |
| `'not_pep'` | Show only `gemini_analyzed = true` AND `gemini_is_pep = false` |

### REQ-002: Gemini Badge Display
**Given** a result row in the resultados table
**When** the result has Gemini analysis data
**Then** display the appropriate badge:

| Condition | Badge |
|-----------|-------|
| `gemini_analyzed = false` | Gray "Pendiente" |
| `gemini_is_pep = true` AND `gemini_categoria = 'PEP'` | Indigo "PEP" |
| `gemini_is_pep = true` AND `gemini_categoria = 'OPI'` | Amber "OPI" |
| `gemini_analyzed = true` AND `gemini_is_pep = false` | Gray "No relevante" |

### REQ-003: Analysis Modal
**Given** a result with `gemini_analyzed = true`
**When** user clicks "Ver análisis" button
**Then** a modal displays:
- Nombre (`gemini_nombre`)
- Cargo (`gemini_cargo`)
- Categoría (badge)
- Confianza (percentage with color: >=70 green, <70 amber)
- Motivo (`gemini_motivo`)

### REQ-004: Confidence Coloring
**Given** a confidence score
**When** displayed in UI
**Then** apply color based on threshold:

| Confidence | Color Class |
|------------|-------------|
| >= 70 | `text-emerald-600` (green) |
| < 70 | `text-amber-600` (amber) |

### REQ-005: Cambios MAE Badge
**Given** a cambio with `gemini_analyzed = true`
**When** `gemini_analisis_json.es_mae = true`
**Then** display red "MAE" badge in card header

### REQ-006: Cambios Analysis Section
**Given** a cambio with `gemini_analyzed = true` AND `gemini_analisis_json` exists
**When** user views the diff panel
**Then** display Gemini analysis section with:
- Persona removida (if present)
- Persona nueva (if present)
- Cargo
- MAE status (badge)
- Riesgo level (badge with color)
- Análisis text (if present)

### REQ-007: Risk Level Coloring
**Given** a risk level in `gemini_analisis_json.riesgo`
**When** displayed in Cambios section
**Then** apply color:

| Risk | Color Class |
|------|-------------|
| `alto` | `bg-red-50 text-red-600` |
| `medio` | `bg-amber-50 text-amber-600` |
| `bajo` | `bg-emerald-50 text-emerald-600` |

### REQ-008: CSV Export with Gemini Columns
**Given** a CSV export request
**When** generating CSV
**Then** include Gemini columns:
- `Gemini_Analizado` (Si/No)
- `Gemini_PEP` (Si/No)
- `Gemini_Categoria`
- `Gemini_Nombre`
- `Gemini_Cargo`
- `Gemini_Confianza`

## Test Coverage

| Requirement | Test File | Tests |
|-------------|-----------|-------|
| REQ-001 | `ResultadosTest.php` | 5 tests (one per filter value) |
| REQ-002 | `ResultadosModalTest.php` | Badge visibility tests |
| REQ-003 | `ResultadosModalTest.php` | 6 tests (modal behavior) |
| REQ-004 | `ResultadosModalTest.php` | Confidence color assertion |
| REQ-005 | `CambiosGeminiTest.php` | MAE badge visibility |
| REQ-006 | `CambiosGeminiTest.php` | Analysis section content |
| REQ-007 | `CambiosGeminiTest.php` | Risk coloring |
| REQ-008 | Manual verification | CSV export checked |

## Implementation Notes

- No database migration required — all columns exist from Phase 1 backend integration
- Filter pattern follows existing `filtroLeido` and `filtroRelevante` conventions
- Modal pattern follows existing `sitios.blade.php` structure
- Confidence threshold (70) matches existing `relevance_score` pattern