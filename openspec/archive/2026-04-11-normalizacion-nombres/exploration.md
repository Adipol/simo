# Exploration: normalizacion-nombres

## Current State

### 1. How Names Are Stored

**`resultados_scraping.gemini_nombre`** (VARCHAR 300, nullable):
- Set by `GeminiFiltroService::persistirResultado()` directly from Gemini's JSON response
- Raw value from LLM — no transformation applied
- Examples in tests: `"Juan Pérez"`, `"Rodrigo Vargas"`, `"Maria Garcia"` (without accents even in test data)
- No index on this column currently

**`clasificaciones_feedback.corregido_nombre`** (VARCHAR 200, nullable):
- User input from feedback modal (`Resultados.php` lines 187, 211)
- Stored verbatim — user correction is authoritative
- Not normalized in any way

### 2. How Names Are Used

**DashboardMetricsService**:
- `computeRecentActivity()` (line 349): selects `gemini_nombre AS nombre` for display — no GROUP BY aggregation
- `computeTopFailingPositions()` (line 429): groups by `gemini_cargo`, NOT `gemini_nombre` — so this widget is NOT affected by name duplication
- The "Recent Activity" widget shows individual PEPs (limit 10), not aggregated counts

**Feedback model**:
- `ClasificacionFeedback` has no aggregation by name currently
- Each feedback is tied to a single `resultado_scraping_id`

**Viewer components**:
- `Resultados.php` displays `gemini_nombre` directly in table and modal
- Blade views use `Str::limit($r->gemini_nombre, 30)` for truncation

### 3. The Deduplication Problem

Currently, the same person detected with different name variants would appear as separate entries:
- "Dr. Juan Pérez" (Gemini includes title)
- "Juan Pérez" (another detection without title)
- "Lic. María García" vs "María García"
- "JUAN PÉREZ" vs "Juan Pérez" (case differences from different Gemini responses)

Without normalization:
- Recent Activity shows "Juan Pérez" twice as two separate people
- If we ever add "persons detected" KPI, it would be wrong
- Future "merge persons" workflow would be impossible without a canonical key

### 4. Key Files

| File | Role |
|------|------|
| `app/Models/ResultadoScraping.php` | `gemini_nombre` in `$fillable` |
| `app/Models/ClasificacionFeedback.php` | `corregido_nombre` in `$fillable` |
| `app/Services/Gemini/GeminiFiltroService.php:52` | Where `gemini_nombre` is set |
| `app/Livewire/Scraper/Resultados.php:211` | Where `corregido_nombre` is saved |
| `app/Services/Dashboard/DashboardMetricsService.php:359` | Uses `gemini_nombre` for display |
| `database/migrations/0001_01_01_000005_create_resultados_scraping_table.php` | Base schema (no index on `gemini_nombre`) |
| `database/migrations/2026_04_05_000001_add_gemini_fields_to_resultados_scraping_table.php` | Adds `gemini_nombre` column |

---

## Normalization Rules for v1

### Rule Definitions (SAFE only — zero false positives)

| # | Rule | Input | Output | Rationale |
|---|------|-------|--------|-----------|
| R1 | Trim whitespace | `"  Juan Pérez  "` | `"Juan Pérez"` | Basic hygiene |
| R2 | Collapse multiple spaces | `"Juan  Pérez"` | `"Juan Pérez"` | Double-space artifacts |
| R3 | Remove academic titles | `"Dr. Juan Pérez"` | `"Juan Pérez"` | See full list below |
| R4 | Remove courtesy titles | `"Sra. María García"` | `"María García"` | See full list below |
| R5 | Title Case | `"JUAN PÉREZ"` | `"Juan Pérez"` | Normalize casing |
| R6 | Strip accents for matching key | `"Juan Pérez"` key → `"Juan Perez"` | — | Accent-insensitive match |
| R7 | Remove trailing punctuation | `"Juan Pérez."` | `"Juan Pérez"` | Period after abbreviation |

**Titles to strip** (case-insensitive with/without period):
```
Dr., Dra., Lic., Licdo., Licda., Ing., Mg., Mtra., Mtro., 
Prof., Profa., Sr., Sra., Srta., Ab., Abg., Don, Doña
```

### Rules NOT implementing in v1 (UNSAFE)

| Rule | Why Unsafe |
|------|------------|
| Expand initials (`J. Pérez` → `Juan Pérez`) | Which Juan? Ambiguous without context |
| Normalize "de la/del/de los" | "De la Vega" vs "Del Vega" are different people |
| Hyphenated surname handling | Not enough signal to merge reliably |
| Phonetic matching (Soundex/Metaphone) | Spanish name phonetics differ from English algorithms |
| Nickname expansion ("Pepe" = "José") | Could cause false merges |

### Transformation Examples

```php
"Dr. Juan Pérez"           → "Juan Pérez"       (R3 + R5)
"Lic. María García"        → "María García"     (R3 + R5)
"  Juan  Pérez  "          → "Juan Pérez"       (R1 + R2)
"JUAN PÉREZ"               → "Juan Pérez"       (R5)
"JUAN PÉREZ" matching key  → "JUAN PEREZ"       (R6)
"Juan Pérez."              → "Juan Pérez"        (R7)
"Ing. Carlos Ruiz Mendoza"  → "Carlos Ruiz Mendoza" (R3 + R5)
```

---

## Architecture

### Service Design

```
app/Services/Normalization/
├── NombreNormalizador.php        ← Core service (pure function)
└── DTOs/
    └── NombreNormalizadoDTO.php  ← Contains original + normalized + matching_key
```

**`NombreNormalizador.php`**:
```php
class NombreNormalizador
{
    public function normalize(string $nombre): NombreNormalizadoDTO;
    public function normalizeNullable(?string $nombre): ?NombreNormalizadoDTO;
}
```

**`NombreNormalizadoDTO`**:
```php
readonly class NombreNormalizadoDTO
{
    public function __construct(
        public string $original,       // "Dr. Juan Pérez" — preserved
        public string $normalized,      // "Juan Pérez" — for display
        public string $matchingKey,     // "juan perez" — accent-stripped, for dedup
    );
}
```

**Design decisions**:
- Pure service (no dependencies, no DB, no state) — trivial to test
- DTO preserves all three forms: original (never lost), normalized (for display), matching key (for dedup)
- Located in `app/Services/Normalization/` — keeps normalization logic isolated
- Not an Action class (overkill for a pure function) and not a macro (not discoverable)

### When to Normalize

1. **On new record creation**: In `GeminiFiltroService::persistirResultado()` — normalize after setting `gemini_nombre`
2. **On feedback submission**: In `Resultados::guardarFeedbackIncorrecto()` — normalize `corregido_nombre` for matching only, preserve original
3. **On dashboard queries**: Use `gemini_nombre_normalizado` for GROUP BY, display original

---

## Storage Strategy

### Option A: Compute on-the-fly

Normalize in PHP every time a query needs deduplication.

**Pros**: No schema change, no data duplication
**Cons**: Cannot index normalized form, slower queries, normalization happens repeatedly

**Verdict**: ❌ Rejected — cannot support efficient GROUP BY or future "merge persons"

### Option B: Pre-compute column (normalized only)

Store only `gemini_nombre_normalizado` in DB, drop original.

**Pros**: Single column, no duplication
**Cons**: Original lost — breaks display fidelity ("Dr. Juan Pérez" becomes just "Juan Pérez")

**Verdict**: ❌ Rejected — constraint requires preserving original

### Option C: Hybrid — store both (RECOMMENDED)

Add `gemini_nombre_normalizado` column alongside existing `gemini_nombre`.

**Pros**: 
- Original preserved for display
- Normalized available for GROUP BY/dedup
- Can add index on normalized column
- Supports future "merge persons" by matching key

**Cons**:
- Slight schema overhead (one VARCHAR column)
- Two columns to maintain

**Verdict**: ✅ Recommended

### Option D: Separate `personas` table with FK

Canonical person records with FK from `resultados_scraping`.

**Pros**: Most normalized, supports rich person metadata
**Cons**: Overkill for v1, significant complexity, migration heavy

**Verdict**: ❌ Deferred — v2 possibility if needed

---

## Approaches Comparison

| Aspect | Option A (on-the-fly) | Option C (hybrid) |
|--------|----------------------|-------------------|
| Schema change | None | 1 column |
| Index support | No | Yes (`gemini_nombre_normalizado`) |
| Query performance | Slower (normalize every query) | Faster (indexed column) |
| Data fidelity | Perfect (original always) | Perfect (both stored) |
| Backfill complexity | None | Migration + command |
| Future "merge persons" | Hard | Easy (matching key available) |
| **Recommendation** | ❌ | ✅ |

---

## Recommendation

**Option C: Hybrid storage with dedicated service**

**Rationale**:
1. The constraint "preserve original `gemini_nombre`" is explicit — must have both forms
2. Dashboard aggregations (if we add "persons detected" KPI) need an indexed normalized column
3. The `NombreNormalizador` service is pure, stateless, and trivially testable — zero integration risk
4. Backfill via `php artisan simo:normalizar-nombres --chunk=500` is safe and can be run incrementally
5. v1 scope is intentionally narrow (SAFE rules only) to minimize false positives

**Key architectural decision**: The matching key (`juan perez`) is accent-stripped but NOT lowercased for display — Title Case is preserved for user-facing output. Only the internal matching key is fully normalized.

---

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| False positive merge | Medium | v1 uses only SAFE rules (no initials expansion, no phonetic) — very conservative |
| Non-Spanish names degraded | Low | Rules target Spanish titles; international names pass through mostly untouched |
| Accent stripping loses info | Low | Only the internal `matching_key` strips accents; display uses `normalized` which preserves accents |
| Backfill performance | Low | `--chunk=500` with progress bar; can run off-hours |
| Index bloat | Low | VARCHAR(300) index is reasonable; only one new index |
| Existing GROUP BY queries break | None | `gemini_nombre` unchanged; existing queries still work |

---

## Scope Boundaries

### IN (v1)
- `NombreNormalizador` service with SAFE rules only
- `gemini_nombre_normalizado` column in `resultados_scraping`
- `gemini_nombre_normalizado` column in `clasificaciones_feedback` (for matching only)
- Index on `gemini_nombre_normalizado`
- `GeminiFiltroService` integration (normalize on persist)
- `Resultados` Livewire integration (normalize `corregido_nombre` for matching only)
- `php artisan simo:normalizar-nombres --chunk=500` backfill command
- Unit tests for `NombreNormalizador` (>90% coverage)

### OUT (future changes)
- Initials expansion
- Phonetic matching (Soundex/Metaphone for Spanish)
- "Persons detected" KPI (requires aggregation, not in v1)
- "Merge persons" workflow
- Country-specific rules (generic Latin American Spanish v1)
- Normalizing `cargo` (position/title) — different problem space
- Any ML/AI-based matching

---

## Migration Plan

### Phase 1: Schema change (non-breaking)
```php
// New migration
$table->string('gemini_nombre_normalizado', 300)->nullable()->after('gemini_nombre');
$table->index('gemini_nombre_normalizado');
```
No existing code affected — column is nullable, all existing records will have NULL until backfill.

### Phase 2: Deploy code changes
1. Deploy `NombreNormalizador` service
2. Update `GeminiFiltroService::persistirResultado()` to normalize on new records
3. Update `Resultados::guardarFeedbackIncorrecto()` to normalize for matching
4. Dashboard continues to use `gemini_nombre` (no change to display yet)

### Phase 3: Backfill (after code deployed)
```bash
php artisan simo:normalizar-nombres --chunk=500
```
Process in batches, log progress. Run during low-traffic period.

### Phase 4: Dashboard integration (optional v1.1)
After backfill completes, update `DashboardMetricsService` to:
- GROUP BY `gemini_nombre_normalizado` instead of `gemini_nombre`
- Add "persons detected" distinct count KPI

---

## Backfill Strategy

### Command Design: `php artisan simo:normalizar-nombres`

**Options**:
- `--chunk=500` — records per batch (default 500)
- `--dry-run` — show what would be updated without persisting
- `--force` — skip confirmation prompt

**Behavior**:
1. Query `resultados_scraping` where `gemini_nombre IS NOT NULL AND gemini_nombre_normalizado IS NULL`
2. Process in chunks of N records
3. For each record: normalize `gemini_nombre`, store in `gemini_nombre_normalizado`
4. Log progress: `Processed 500/15000 (3.3%)`
5. On completion: `Backfill complete. 15000 records updated.`

**Safety**:
- Idempotent — can be run multiple times safely (only updates NULLs)
- Dry-run mode for verification before actual changes
- No destructive operations — only adds to `gemini_nombre_normalizado` column

---

## Data Flow

```
Gemini Response
      │
      ▼
┌─────────────────────────────────┐
│ GeminiFiltroService::persistir  │
│                                 │
│ $dto->nombre                    │
│      │                          │
│      ▼                          │
│ NombreNormalizador::normalize   │
│      │                          │
│      ├──► $dto->original  "Dr. Juan Pérez"  → stored as gemini_nombre
│      ├──► $dto->normalized "Juan Pérez"      → stored as gemini_nombre_normalizado  
│      └──► $dto->matchingKey "juan perez"     → used only for internal matching
└─────────────────────────────────┘
```

---

## Affected Areas Summary

| File | Change |
|------|--------|
| `app/Services/Normalization/NombreNormalizador.php` | NEW — pure normalization service |
| `app/Services/Normalization/DTOs/NombreNormalizadoDTO.php` | NEW — DTO with three forms |
| `app/Services/Gemini/GeminiFiltroService.php` | MODIFY — normalize after persist |
| `app/Livewire/Scraper/Resultados.php` | MODIFY — normalize `corregido_nombre` for matching |
| `app/Models/ResultadoScraping.php` | MODIFY — add `gemini_nombre_normalizado` to fillable |
| `app/Models/ClasificacionFeedback.php` | MODIFY — add `corregido_nombre_normalizado` to fillable |
| `app/Services/Dashboard/DashboardMetricsService.php` | MODIFY — GROUP BY normalized column (v1.1) |
| `database/migrations/_add_gemini_nombre_normalizado.php` | NEW — add column + index |
| `app/Console/Commands/NormalizarNombresCommand.php` | NEW — backfill command |
| `tests/Unit/Services/NombreNormalizadorTest.php` | NEW — unit tests |
