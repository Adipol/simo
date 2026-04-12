# SDD Specifications: lematizacion-pep-opi

**Status**: Draft  
**Change**: lematizacion-pep-opi  
**Topic Key**: `sdd/lematizacion-pep-opi/spec`  
**Date**: 2026-04-11  

---

## Capability: pep-positions-catalog (NEW)

### Purpose
Database-backed catalog of PEP positions and public entities by country, enabling dynamic prompt generation for Gemini-based PEP detection.

### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| REQ-1 | System MUST store PEP positions per country with entity type classification | MUST |
| REQ-2 | System MUST store known public entities per country | MUST |
| REQ-3 | System MUST support three entity type classifications: `todas`, `publica`, `ambas` | MUST |
| REQ-4 | Each position MUST belong to exactly one country (FK constraint) | MUST |
| REQ-5 | Each public entity MUST belong to exactly one country (FK constraint) | MUST |
| REQ-6 | Positions SHOULD be grouped by category (Ejecutivo, Legislativo, Judicial, etc.) | SHOULD |
| REQ-7 | Positions and entities MUST support soft deactivation (`activo` boolean) | MUST |
| REQ-8 | System MUST seed Bolivia with 97 official PEP positions on first install | MUST |
| REQ-9 | System MUST seed Bolivia's known public entities (YPFB, ENDE, ENTEL, Banco Unión, universities) | MUST |

### Scenarios

#### Scenario: Creating a position for a country
- GIVEN country "Bolivia" exists in `paises` table
- WHEN admin creates position "Ministro de Economía" with `entidad_tipo = 'publica'` and `categoria = 'Ejecutivo'`
- THEN position is stored with `pais_codigo = 'BO'`
- AND `activo = true` by default

#### Scenario: Classifying a position as `todas`
- GIVEN position "Diputado Nacional" is being created
- WHEN `entidad_tipo = 'todas'` is set
- THEN position is flagged as ALWAYS PEP regardless of entity

#### Scenario: Classifying a position as `publica`
- GIVEN position "Presidente" is being created
- WHEN `entidad_tipo = 'publica'` is set
- THEN position is flagged as PEP only when in public entity

#### Scenario: Classifying a position as `ambas`
- GIVEN position "Gerente" is being created
- WHEN `entidad_tipo = 'ambas'` is set
- THEN position is flagged as PEP depending on context (entity type matters)

#### Scenario: Querying positions by country (active only)
- GIVEN 100 positions exist for Bolivia (97 active, 3 inactive)
- WHEN querying with `activo = true` filter
- THEN only 97 positions are returned

#### Scenario: Grouping positions by category
- GIVEN positions exist with categories: Ejecutivo, Legislativo, Judicial
- WHEN querying with category grouping
- THEN results are organized by category

#### Scenario: Deactivating a position without deleting
- GIVEN position "Ministro" exists and is active
- WHEN `activo` is set to `false`
- THEN position remains in database but is excluded from prompts
- AND historical references remain intact

#### Scenario: Foreign key constraint violation
- GIVEN position is being created with invalid `pais_codigo = 'XX'`
- WHEN save is attempted
- THEN database rejects with FK constraint error

#### Scenario: Bolivia seeder runs successfully
- GIVEN fresh database installation
- WHEN `CargosPepBoliviaSeeder` runs
- THEN exactly 97 positions are inserted
- AND all have `pais_codigo = 'BO'`
- AND `entidad_tipo` is set correctly for each position

---

## Capability: gemini-pep-filter (MODIFIED)

### Purpose
Dynamic PEP prompt generation using database-backed position catalog instead of hardcoded definitions.

### MODIFIED Requirements

#### Requirement: Dynamic PEP prompt generation

The system MUST generate PEP classification prompts by loading position definitions from the database instead of using hardcoded text.

(Previously: Used hardcoded definitions: "ministros, legisladores, jueces, directores de entes públicos, militares de alto rango, embajadores")

##### Scenario: Building prompt with 3 sections for Bolivia
- GIVEN Bolivia has positions loaded: 10 `todas`, 25 `publica`, 62 `ambas`
- WHEN `filtroPEP('texto', 'Bolivia', 'PEP')` is called
- THEN prompt contains SIEMPRE_PEP section with 10 positions
- AND prompt contains PEP_EN_ENTIDAD_PUBLICA section with 25 positions
- AND prompt contains PUEDE_SER_PEP section with 62 positions + public entities list

##### Scenario: Fallback to generic prompt when no positions exist
- GIVEN country "Chile" has no positions in database
- WHEN `filtroPEP('texto', 'Chile', 'PEP')` is called
- THEN prompt falls back to generic hardcoded definitions
- AND includes warning that country-specific data is unavailable

##### Scenario: Excluding inactive positions from prompt
- GIVEN Bolivia has 100 positions total (3 marked `activo = false`)
- WHEN `filtroPEP('texto', 'Bolivia', 'PEP')` is called
- THEN only 97 positions appear in prompt
- AND the 3 inactive positions are excluded

##### Scenario: Including public entities in PUEDE_SER_PEP section
- GIVEN Bolivia has 5 public entities seeded (YPFB, ENDE, ENTEL, Banco Unión, UMSA)
- WHEN `filtroPEP('texto', 'Bolivia', 'PEP')` is called
- THEN PUEDE_SER_PEP section lists all 5 entities
- AND entities marked `activo = false` are excluded

##### Scenario: Response JSON includes entidad_tipo field
- GIVEN Gemini returns classification for "Ministro de Economía de YPFB"
- WHEN response is parsed
- THEN JSON contains `entidad_tipo` field with value `publica`
- AND valid values are: `publica`, `privada`, `desconocido`

### ADDED Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| REQ-1 | `filtroPEP()` MUST accept country code and load positions for that country | MUST |
| REQ-2 | `filtroPEP()` MUST build prompt with 3 sections: SIEMPRE_PEP, PEP_EN_ENTIDAD_PUBLICA, PUEDE_SER_PEP | MUST |
| REQ-3 | SIEMPRE_PEP section MUST contain positions with `entidad_tipo = 'todas'` | MUST |
| REQ-4 | PEP_EN_ENTIDAD_PUBLICA section MUST contain positions with `entidad_tipo = 'publica'` | MUST |
| REQ-5 | PUEDE_SER_PEP section MUST contain positions with `entidad_tipo = 'ambas'` PLUS known public entities | MUST |
| REQ-6 | `filtroPEP()` MUST NOT execute N+1 queries when called multiple times | MUST |
| REQ-7 | If no positions exist for country, `filtroPEP()` MUST fall back to generic prompt | MUST |
| REQ-8 | Only `activo = true` positions and entities MUST be included in prompt | MUST |
| REQ-9 | Response JSON MUST include `entidad_tipo` field with values `publica`\|`privada`\|`desconocido` | MUST |

### Scenarios (ADDED)

#### Scenario: Preventing N+1 query problem
- GIVEN `filtroPEP()` is called 100 times for Bolivia
- WHEN query log is inspected
- THEN only 1 query executes for positions (cached)
- AND only 1 query executes for entities (cached)

#### Scenario: Bolivia prompt contains all required sections
- GIVEN Bolivia is fully seeded
- WHEN prompt is generated
- THEN prompt structure follows:
  ```
  SIEMPRE_PEP (siempre son PEP):
  - Diputado, Senador, Asambleísta...
  
  PEP_EN_ENTIDAD_PUBLICA (PEP solo si están en entidad pública):
  - Presidente, Ministro, Fiscal General...
  
  PUEDE_SER_PEP (PEP depende del contexto, considerar si están en estas entidades):
  - Gerente, Director, Rector...
  Entidades públicas conocidas: YPFB, ENDE, ENTEL, Banco Unión...
  ```

---

## Non-Functional Requirements

### Performance
- Position queries MUST use eager loading to prevent N+1
- Query results SHOULD be cached for at least 5 minutes
- Database indexes MUST exist on: `cargos_pep(pais_codigo, activo)`, `entidades_publicas(pais_codigo, activo)`

### Backward Compatibility
- Generic prompt fallback MUST work for countries without seed data
- Existing tests MUST pass with minor updates for new response field
- JSON response structure MUST remain backward compatible (adding `entidad_tipo` is additive)

### Data Integrity
- Soft deletes via `activo` flag MUST be used instead of hard deletes
- Foreign keys MUST enforce referential integrity to `paises` table
- Seeders MUST be idempotent (running twice doesn't duplicate data)

---

## Test Coverage Summary

| Capability | Happy Paths | Edge Cases | Error States |
|------------|-------------|------------|--------------|
| pep-positions-catalog | 4 | 3 | 1 |
| gemini-pep-filter | 3 | 3 | 1 |

---

## RFC 2119 Keywords Used

- **MUST/SHALL**: Absolute requirement
- **SHOULD**: Recommended, exceptions need justification
- **MAY**: Optional
