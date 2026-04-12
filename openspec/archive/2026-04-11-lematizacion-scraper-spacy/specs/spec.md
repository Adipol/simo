# Specifications: Lemmatization — Scraper + spaCy

**Change**: `lematizacion-scraper-spacy`  
**Primary Success Criterion**: `"designar"` matches `"designación"`  
**Version**: 1.0  
**Date**: 2026-04-11

---

## Overview

This specification defines the behavior for adding morphological lemmatization to the Python scraper's `KeywordMatcher`. The system MUST enable keyword "designar" to match article titles containing "designación", "designó", "designado", and other variants. The implementation consists of three capabilities:

1. **lemma-families-catalog** (NEW) — Laravel-side DB catalog with admin UI
2. **scraper-lemma-matching** (NEW) — Python-side keyword expansion via families
3. **scraper-keywords-ui** (MODIFIED) — Extended admin navigation

---

## Capability 1: lemma-families-catalog

### Purpose

Provide a database-backed catalog of Spanish word families (raíz → variantes) with administrative CRUD interface. Each family groups morphologically related words under a canonical root form.

### Requirements

#### REQ-1: Database Schema

The system MUST store word families with the following fields:

| Field | Type | Constraints |
|-------|------|-------------|
| `id` | BIGINT UNSIGNED | PRIMARY KEY, auto-increment |
| `raiz` | VARCHAR(100) | NOT NULL, UNIQUE |
| `variantes` | JSON | NOT NULL |
| `categoria` | VARCHAR(50) | NOT NULL |
| `activo` | BOOLEAN | NOT NULL, DEFAULT true |
| `timestamps` | TIMESTAMP | created_at, updated_at |

**Scenario: Creating valid family**

- GIVEN a clean database
- WHEN migration `create_familias_lemas_table` runs
- THEN table `familias_lemas` exists with all columns
- AND `raiz` has UNIQUE constraint

#### REQ-2: Unique Root Constraint

The system MUST enforce UNIQUE constraint on `raiz` column at database level.

**Scenario: Duplicate root rejection**

- GIVEN family with `raiz="designar"` exists
- WHEN attempting to insert another row with `raiz="designar"`
- THEN database raises unique constraint violation

#### REQ-3: Category Support

The system MUST support exactly three categorías: `designacion`, `renuncia`, `crimen`.

**Scenario: Valid categories accepted**

- GIVEN the families table exists
- WHEN inserting families with categories `designacion`, `renuncia`, `crimen`
- THEN all insertions succeed

**Scenario: Invalid category rejected**

- GIVEN validation rules in place
- WHEN attempting to create family with `categoria="invalido"`
- THEN validation fails with error on categoria field

#### REQ-4: JSON Variants Storage

The system MUST store `variantes` as JSON array for cross-database portability (SQLite tests + PostgreSQL production).

**Scenario: JSON storage on SQLite**

- GIVEN SQLite test database
- WHEN creating family with `variantes=["designación", "designado"]`
- THEN data persists as JSON
- AND Eloquent casts return as PHP array

**Scenario: JSON storage on PostgreSQL**

- GIVEN PostgreSQL production database
- WHEN creating family with `variantes=["designación", "designado"]`
- THEN data persists as JSON
- AND Eloquent casts return as PHP array

#### REQ-5: Model Casts

Model `FamiliaLema` MUST cast `variantes` as array and `activo` as boolean.

**Scenario: Automatic casting**

- GIVEN a `FamiliaLema` instance
- WHEN accessing `$familia->variantes`
- THEN returns PHP array (not JSON string)
- AND when accessing `$familia->activo`
- THEN returns boolean (not integer)

#### REQ-6: Query Scopes

Model MUST expose scopes: `active()` and `byCategoria(string $categoria)`.

**Scenario: Active scope**

- GIVEN 3 active families and 2 inactive families
- WHEN calling `FamiliaLema::active()->get()`
- THEN returns exactly 3 families with `activo=true`

**Scenario: By category scope**

- GIVEN 9 families with `categoria="designacion"`
- WHEN calling `FamiliaLema::byCategoria('designacion')->get()`
- THEN returns exactly 9 families

#### REQ-7: Database Indexes

Migration MUST create indexes on `activo` and `categoria` columns.

**Scenario: Index existence**

- GIVEN migration has run
- WHEN querying `SHOW INDEX FROM familias_lemas` (or equivalent)
- THEN indexes exist on columns `activo` and `categoria`

#### REQ-8: Seeder Data Count

Seeder `FamiliasLemasSeeder` MUST insert exactly 33 families:
- 9 families with `categoria="designacion"`
- 8 families with `categoria="renuncia"`
- 16 families with `categoria="crimen"`

**Scenario: Seeder count verification**

- GIVEN empty database
- WHEN running `FamiliasLemasSeeder`
- THEN exactly 33 rows exist in `familias_lemas`
- AND count by category matches distribution above

#### REQ-9: Idempotent Seeder

Seeder MUST be idempotent — second run inserts 0 new rows.

**Scenario: Idempotent execution**

- GIVEN seeder has run once (33 families exist)
- WHEN running seeder again
- THEN no new rows inserted
- AND total count remains 33

#### REQ-10: Permission Assignment

Permission `gestionar familias lemas` MUST be assigned to `admin` role ONLY (not supervisor, not operador).

**Scenario: Admin has permission**

- GIVEN user with `admin` role
- WHEN checking `user->can('gestionar familias lemas')`
- THEN returns true

**Scenario: Supervisor lacks permission**

- GIVEN user with `supervisor` role
- WHEN checking `user->can('gestionar familias lemas')`
- THEN returns false

**Scenario: Operador lacks permission**

- GIVEN user with `operador` role
- WHEN checking `user->can('gestionar familias lemas')`
- THEN returns false

#### REQ-11: Livewire CRUD Operations

Livewire component MUST provide CRUD operations: list, create, edit, delete, toggle active.

**Scenario: List families**

- GIVEN 33 families in database
- WHEN loading Livewire component
- THEN displays paginated list of families

**Scenario: Create family**

- GIVEN admin user with permission
- WHEN submitting create form with valid data
- THEN new family persisted to database

**Scenario: Edit family**

- GIVEN existing family
- WHEN submitting edit form with modified data
- THEN changes persisted to database

**Scenario: Delete family**

- GIVEN existing family
- WHEN confirming delete action
- THEN family removed from database

**Scenario: Toggle active**

- GIVEN active family
- WHEN clicking toggle button
- THEN `activo` flips to false
- AND family excluded from active queries

#### REQ-12: Form Validation

Livewire create/edit form MUST validate:
- `raiz`: required, unique (case-insensitive), max 100 chars
- `variantes`: required, non-empty array, max 50 variants
- `categoria`: required, enum in [designacion, renuncia, crimen]
- `activo`: boolean

**Scenario: Valid data accepted**

- GIVEN form with `raiz="testar"`, `variantes=["test"]`
- WHEN submitting
- THEN validation passes

**Scenario: Duplicate raiz rejected**

- GIVEN family with `raiz="designar"` exists
- WHEN creating new family with `raiz="designar"`
- THEN validation fails with "raiz already exists"

**Scenario: Empty variantes rejected**

- GIVEN form with `variantes=[]`
- WHEN submitting
- THEN validation fails with "variantes required"

#### REQ-13: Authorization Gates

Livewire UI MUST be gated by `@can('gestionar familias lemas')` in Blade AND `$this->authorize()` in actions.

**Scenario: Blade gate blocks unauthorized**

- GIVEN supervisor user (no permission)
- WHEN accessing page with `@can('gestionar familias lemas')` wrapper
- THEN UI elements hidden

**Scenario: Action gate blocks direct POST**

- GIVEN supervisor user (no permission)
- WHEN sending direct POST to Livewire action
- THEN receives 403 Forbidden

#### REQ-14: Route Protection

Route `/scraper/familias-lemas` MUST require authentication + permission middleware.

**Scenario: Admin access granted**

- GIVEN authenticated admin user
- WHEN GET `/scraper/familias-lemas`
- THEN returns 200 OK

**Scenario: Supervisor access denied**

- GIVEN authenticated supervisor user
- WHEN GET `/scraper/familias-lemas`
- THEN returns 403 Forbidden

**Scenario: Operador access denied**

- GIVEN authenticated operador user
- WHEN GET `/scraper/familias-lemas`
- THEN returns 403 Forbidden

**Scenario: Unauthenticated redirected**

- GIVEN unauthenticated user
- WHEN GET `/scraper/familias-lemas`
- THEN redirect to login page

#### REQ-15: Admin Navigation Entry

Admin navigation menu MUST include entry "Familias de lemas" only when user has the permission.

**Scenario: Admin sees menu entry**

- GIVEN admin user with permission
- WHEN viewing admin navigation
- THEN sees "Familias de lemas" entry

**Scenario: Supervisor does not see menu entry**

- GIVEN supervisor user without permission
- WHEN viewing admin navigation
- THEN does NOT see "Familias de lemas" entry

---

## Capability 2: scraper-lemma-matching

### Purpose

Expand the Python scraper's `KeywordMatcher` to support morphological matching via lemma families loaded from the database. Keywords are expanded to include all family variants at initialization time.

### Requirements

#### REQ-1: Keyword Expansion

`KeywordMatcher.__init__` MUST expand each keyword via the lemma families loaded from DB.

**Scenario: Family expansion**

- GIVEN `KeywordMatcher(["designar"])`
- AND family `{designar: [designación, designado]}` loaded
- WHEN initialized
- THEN internal regex includes all variants

#### REQ-2: Exact Root Match

Keyword expansion MUST use exact match on `raiz` field (case-insensitive).

**Scenario: Case-insensitive root matching**

- GIVEN family with `raiz="Designar"`
- WHEN initializing with keyword `"designar"`
- THEN expansion occurs (case-insensitive match)

#### REQ-3: Unknown Keywords Pass-Through

If a keyword is NOT in any family, it MUST remain as-is (no expansion).

**Scenario: Unknown keyword unchanged**

- GIVEN `KeywordMatcher(["unknown_keyword"])`
- AND no family contains "unknown_keyword"
- WHEN initialized
- THEN regex pattern is `\bunknown_keyword\b`

#### REQ-4: Word Boundary Regex

Expanded regex pattern MUST combine all variants with `|` alternation and `\b...\b` word boundaries.

**Scenario: Pattern construction**

- GIVEN keyword `"designar"` with variants `["designación", "designado"]`
- WHEN `KeywordMatcher` initializes
- THEN pattern is `\b(designar|designación|designado)\b`

#### REQ-5: Case-Insensitive Matching

Expanded regex MUST remain case-insensitive (`re.IGNORECASE` flag).

**Scenario: Case-insensitive matching**

- GIVEN pattern `\b(designar|designación)\b` with IGNORECASE
- WHEN matching against "DESIGNACIÓN"
- THEN match succeeds

#### REQ-6: Morphological Match Success

`keyword_in_title(title, "designar")` MUST return True for titles containing morphological variants.

**Scenario: Noun form match**

- GIVEN title `"Designación de nuevo ministro"`
- AND keyword `"designar"` with family expansion
- WHEN calling `keyword_in_title(title, "designar")`
- THEN returns True

**Scenario: Past tense match**

- GIVEN title `"Presidente designó a su gabinete"`
- AND keyword `"designar"` with family expansion
- WHEN calling `keyword_in_title(title, "designar")`
- THEN returns True

**Scenario: Past participle match**

- GIVEN title `"Ministro designado deja el cargo"`
- AND keyword `"designar"` with family expansion
- WHEN calling `keyword_in_title(title, "designar")`
- THEN returns True

**Scenario: False positive prevention**

- GIVEN title `"Designer de modas"`
- AND keyword `"designar"`
- WHEN calling `keyword_in_title(title, "designar")`
- THEN returns False (word boundary prevents substring match)

#### REQ-7: spaCy Singleton Loading

spaCy model `es_core_news_sm` MUST be loaded ONCE at module level (not per instance).

**Scenario: Single load**

- GIVEN multiple `KeywordMatcher` instances created
- WHEN monitoring spaCy loading
- THEN model loaded exactly one time

#### REQ-8: spaCy Graceful Degradation

spaCy loading failure MUST NOT crash the scraper — graceful fallback to regex-only matching.

**Scenario: spaCy import failure**

- GIVEN spaCy not installed or model missing
- WHEN initializing `KeywordMatcher`
- THEN scraper continues with regex-only matching
- AND warning logged

#### REQ-9: DB Query Fallback

DB query failure during family loading MUST fall back to hardcoded minimal dict (safety net).

**Scenario: Database unavailable**

- GIVEN database connection fails
- WHEN `KeywordMatcher` initializes
- THEN falls back to hardcoded minimal families
- AND warning logged

#### REQ-10: Degradation Logging

System MUST log warnings on each degradation level:
- No spaCy available
- No DB families loaded
- No families in fallback

**Scenario: spaCy warning**

- GIVEN spaCy unavailable
- WHEN initializing matcher
- THEN log contains WARNING: "spaCy model not available"

**Scenario: DB fallback warning**

- GIVEN DB query fails
- WHEN loading families
- THEN log contains WARNING: "Failed to load families from DB, using fallback"

#### REQ-11: Backward Compatibility

`calculate_relevance` behavior MUST remain backward compatible — title match gives highest score.

**Scenario: Relevance scoring unchanged**

- GIVEN title match via expanded family
- WHEN calling `calculate_relevance`
- THEN returns highest score (same as before expansion)

#### REQ-12: Context Extraction

`extract_context` MUST return context around any matched variant, not just the original keyword.

**Scenario: Context around variant**

- GIVEN text `"La designación fue ayer"`
- AND keyword `"designar"` matching "designación"
- WHEN calling `extract_context(text, "designar")`
- THEN returns context around "designación"

#### REQ-13: Actual Match Return

`find_in_text` MUST return the ACTUAL matched variant string, not the canonical raiz.

**Scenario: Return matched variant**

- GIVEN text `"designó a Juan"`
- AND keyword `"designar"`
- WHEN calling `find_in_text(text)`
- THEN returns `["designó"]` (not `["designar"]`)

#### REQ-14: Multiple Keyword Expansion

Multiple keywords in init MUST all be expanded if families exist.

**Scenario: Multiple keywords**

- GIVEN `KeywordMatcher(["designar", "renunciar"])`
- AND families exist for both
- WHEN initialized
- THEN regex includes variants from both families

---

## Capability 3: scraper-keywords-ui (Modified)

### Purpose

Extend the existing admin navigation to include access to the new lemma families management page while keeping the existing `/scraper/keywords` functionality unchanged.

### Requirements

#### REQ-1: New Navigation Entry

Admin menu MUST include a new entry "Familias de lemas" linking to `/scraper/familias-lemas`.

**Scenario: Entry visible to admin**

- GIVEN admin user with `gestionar familias lemas` permission
- WHEN viewing admin navigation
- THEN sees "Familias de lemas" entry
- AND link points to `/scraper/familias-lemas`

#### REQ-2: Permission-Based Visibility

The menu entry MUST be visible ONLY to users with `gestionar familias lemas` permission.

**Scenario: Entry hidden from supervisor**

- GIVEN supervisor user without permission
- WHEN viewing admin navigation
- THEN does NOT see "Familias de lemas" entry

**Scenario: Entry hidden from operador**

- GIVEN operador user without permission
- WHEN viewing admin navigation
- THEN does NOT see "Familias de lemas" entry

#### REQ-3: Existing Keywords Unchanged

The existing `/scraper/keywords` page and functionality MUST remain unchanged.

**Scenario: Keywords page intact**

- GIVEN existing keywords management page
- WHEN accessing `/scraper/keywords`
- THEN all existing functionality works as before
- AND no references to lemma families appear

#### REQ-4: Menu Placement

The new menu entry MUST appear in the same admin section as existing Scraper entries (near Sitios, Keywords).

**Scenario: Menu placement**

- GIVEN admin navigation menu
- WHEN examining Scraper section
- THEN "Familias de lemas" appears alongside Sitios and Keywords

---

## Non-Functional Requirements

### Performance (NF-PERF)

| ID | Requirement | Target |
|----|-------------|--------|
| NF-PERF-1 | Scraper startup overhead with spaCy loaded | < 3 seconds |
| NF-PERF-2 | Family loading from DB (33 rows) | < 100ms |
| NF-PERF-3 | Per-title matching latency (regex) | < 5ms |
| NF-PERF-4 | spaCy model memory footprint | < 150 MB RAM |
| NF-PERF-5 | Full scraper run (200 links × 1 site) | < 90s with spaCy |

### Reliability (NF-REL)

| ID | Requirement |
|----|-------------|
| NF-REL-1 | System MUST gracefully degrade in 3 levels: spaCy unavailable → DB unavailable → fallback dict → original keywords |
| NF-REL-2 | NO failure mode in the lemmatization pipeline MUST cause scraper to crash |
| NF-REL-3 | Each degradation level MUST emit a WARNING log |

**Scenario: Three-level degradation**

- GIVEN spaCy unavailable AND DB unavailable
- WHEN scraper initializes
- THEN falls back to hardcoded dict
- AND logs 2 WARNING messages

### Testability (NF-TEST)

| ID | Requirement |
|----|-------------|
| NF-TEST-1 | Python `tests/` directory MUST contain at least 15 test cases |
| NF-TEST-2 | Tests MUST cover: lemma expansion, family loading, fallback modes, regex behavior |
| NF-TEST-3 | Tests MUST NOT require a live PostgreSQL connection (use mocks/fixtures) |
| NF-TEST-4 | pytest MUST run with `pytest` command from scraper_v2.2 directory |

**Scenario: Test suite execution**

- GIVEN clean Python environment
- WHEN running `pytest` from `scripts/scraper_v2.2/`
- THEN at least 15 tests execute
- AND all tests pass

### Data Integrity (NF-DATA)

| ID | Requirement |
|----|-------------|
| NF-DATA-1 | `raiz` column MUST be unique (DB-level constraint) |
| NF-DATA-2 | Deleting a family MUST NOT cascade to `palabras_clave` or `resultados_scraping` |
| NF-DATA-3 | Toggling `activo = false` MUST exclude the family from Python loading |

### Security (NF-SEC)

| ID | Requirement |
|----|-------------|
| NF-SEC-1 | CRUD operations MUST enforce permission check at Livewire action level |
| NF-SEC-2 | Permission check MUST NOT be bypassed via direct HTTP POST |
| NF-SEC-3 | User-provided `variantes` MUST NOT be executed as code or SQL |

### Portability (NF-PORT)

| ID | Requirement |
|----|-------------|
| NF-PORT-1 | Migration MUST run on SQLite (tests) AND PostgreSQL (dev + prod) |
| NF-PORT-2 | `variantes` column MUST use JSON type for cross-DB compatibility |
| NF-PORT-3 | Python code MUST work on Python 3.10+ |

### Accessibility (NF-A11Y)

| ID | Requirement |
|----|-------------|
| NF-A11Y-1 | Livewire UI tables MUST have proper table headers (`<th>`) |
| NF-A11Y-2 | Form fields MUST have associated labels |
| NF-A11Y-3 | Buttons MUST have descriptive text (not icon-only) |

---

## Specification Summary

| Capability | Type | Requirements | Scenarios |
|------------|------|--------------|-----------|
| lemma-families-catalog | NEW | 15 | 35+ |
| scraper-lemma-matching | NEW | 14 | 18+ |
| scraper-keywords-ui | MODIFIED | 4 | 6+ |
| **Total** | — | **33** | **59+** |

### Critical Test Cases (from Proposal)

These three concrete test cases MUST pass:

1. ✅ `KeywordMatcher(["designar"])` + title `"Designación de nuevo ministro"` → **MATCH**
2. ✅ `KeywordMatcher(["designar"])` + title `"Presidente designó a su gabinete"` → **MATCH**
3. ✅ `KeywordMatcher(["designar"])` + title `"Ministro designado deja el cargo"` → **MATCH**

### Graceful Degradation Levels

```
Level 1: spaCy unavailable → regex-only matching
Level 2: DB unavailable → hardcoded minimal families
Level 3: No families → original keywords only
```

Each level MUST log a WARNING message.

---

## Related Documents

- Exploration: `openspec/changes/lematizacion-scraper-spacy/exploration.md`
- Proposal: `openspec/changes/lematizacion-scraper-spacy/proposal.md`
- Seed Data: 33 lemma families defined in proposal (9 designacion + 8 renuncia + 16 crimen)

---

*End of Specification*
