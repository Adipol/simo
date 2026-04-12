# Exploration: Sistema Feedback Clasificaciones

## Current State

### How the Scraper\Resultados Livewire Component Works Today

The `Resultados` component (`app/Livewire/Scraper/Resultados.php`) is the main listing page for scraping results. Key characteristics:

**Architecture:**
- Uses `WithPagination` trait for paginated results (25 per page)
- Query building via `buildQuery()` method with multiple filter state properties (`filtroPais`, `filtroGemini`, etc.)
- `resultadoAnalisis` computed property fetches a single record when `verAnalisisId` is set
- Inline actions: `marcarLeido()`, `marcarRelevante()`, `descartar()`, `restaurar()` — all direct DB updates via `where()->update()`

**Current Row Actions (from blade lines 147-179):**
```
[Leido] [Relevante] [X(Descartar)] [Ver análisis]
```
- "Leido" button: marks as read (only if not already read)
- "Relevante" toggle: toggles the relevante boolean
- "Descartar" (danger): marks as discarded with confirmation dialog
- "Restaurar": appears only for discarded rows
- "Ver análisis": opens the Gemini analysis modal

**Modal Pattern:**
- `verAnalisisId` property controls modal visibility
- Modal displays: nombre, cargo, categoría, confianza %, motivo
- Closes on backdrop click (`wire:click.self`) or close button

**Permission Model:**
- Spatie laravel-permission
- Inline `@can('permission name')` in blade templates
- Route-level middleware for CRUD routes (`permission:gestionar sitios`)
- Existing permissions for scraper: `ver resultados scraper`, `marcar leido`, `marcar relevante`, `exportar csv scraper`

---

## Affected Areas

| File | Why Affected |
|------|--------------|
| `app/Livewire/Scraper/Resultados.php` | Must add feedback action methods and modal state |
| `resources/views/livewire/scraper/resultados.blade.php` | Must add feedback buttons per row and feedback modal |
| `app/Models/ResultadoScraping.php` | Will need new relationship to feedback |
| `database/seeders/RolesPermisosSeeder.php` | Must add `dar feedback clasificaciones` permission |
| `app/Models/User.php` | Will need relationship to feedback (already has HasRoles trait) |
| `database/migrations/` | New migration for `clasificaciones_feedback` table |

---

## Approaches

### Approach 1: Inline Feedback Buttons + Modal in Resultados Component
**Add feedback actions directly in the existing Resultados component.**

- **Pros:**
  - Minimal new files (no new Livewire component)
  - Shares existing modal infrastructure (`verAnalisisId` pattern)
  - Easier to implement incrementally
  - User stays on same page

- **Cons:**
  - Resultados.php grows in responsibility (violates SRP somewhat)
  - Modal already shows Gemini analysis — would need to either expand it or create a second modal
  - Component already has 7 action methods; adding more makes it busier

- **Effort:** Low-Medium

---

### Approach 2: Dedicated FeedbackModal Livewire Component
**Create a separate `Scraper/FeedbackModal.php` component that opens via Events.**

- **Pros:**
  - Clean separation of concerns
  - Reusable if feedback is needed elsewhere (e.g., PEP Monitor)
  - Simpler Resultados.php (just dispatches event)
  - Easier to test in isolation

- **Cons:**
  - More files to create
  - Requires learning event dispatch pattern (`dispatch()` / `dispatchTo()`)
  - Slight overhead in wiring between components

- **Effort:** Medium

---

### Approach 3: Full Page Route for Feedback (`/scraper/resultado/{id}/feedback`)
**Create a dedicated feedback page with its own route.**

- **Pros:**
  - Most explicit, handles complex feedback flows well
  - Browser history works naturally (back button)
  - Could support rich editing of multiple feedback entries

- **Cons:**
  - Overkill for simple correct/incorrect + optional reason
  - Navigation overhead (user leaves the listing)
  - More UI surface area to maintain
  - Doesn't match project pattern (all other scraper actions are inline or modal)

- **Effort:** High

---

## Recommendation

**Approach 2: Dedicated FeedbackModal Livewire Component** is the best fit.

**Rationale:**
1. The feedback action is conceptually distinct from listing/filtering — it deserves its own component
2. Approach 1 would require either cramming feedback into the existing analysis modal (too crowded) or adding a second modal (messy)
3. The project already has a pattern of modal-based interactions (Keywords blade has a full CRUD modal in a single component, but that component is simpler)
4. A separate component can be tested in isolation and potentially reused
5. The event dispatch pattern (`dispatch('openFeedback', $resultadoId)`) keeps the parent component clean

**However**, given the constraint to "follow existing project patterns," the hybrid approach makes sense:
- Inline quick-action buttons (✅ Correcto / ❌ Incorrecto) in the row
- A single feedback modal that handles both "correct" (just confirm) and "incorrect" (capture reason + correction)
- Keep feedback state in Resultados.php using a new property `feedbackModalId` (similar to `verAnalisisId`)

This matches the existing pattern where `verAnalisisId` controls a modal AND the component handles the data fetching via a computed property.

---

## Proposed DB Schema

### Table: `clasificaciones_feedback`

```php
// Migration columns
$resultadoScrapingId;    // FK -> resultados_scraping.id
$usuarioId;              // FK -> users.id  
$tipo;                   // enum('correcto', 'incorrecto')
$clasificacionSnapshot;  // JSON: {is_pep, nombre, cargo, entidad_tipo, categoria, confianza}
// At time of feedback, snapshot what Gemini said
$corregidoIsPep;         // boolean, nullable (what user thinks is correct)
$corregidoCategoria;     // enum('PEP', 'OPI', 'NO_REL', null) nullable
$corregidoNombre;        // string, nullable
$corregidoCargo;         // string, nullable
$m motivo;               // text, nullable
$createdAt;              // timestamp
$updatedAt;              // timestamp

// Constraints & Indexes
// - PRIMARY KEY (resultado_scraping_id, usuario_id) — one feedback per user per result
// - INDEX on (resultado_scraping_id) for dashboard aggregation
// - INDEX on (usuario_id) for "my feedback" queries
// - INDEX on (tipo) for correct/incorrect counts
// - INDEX on (created_at) for time-series analysis
// - ON DELETE: cascade (if ResultadoScraping is deleted, feedback is deleted too)
```

**Design Decisions:**

1. **Composite PK on (resultado_scraping_id, usuario_id)**: Ensures one feedback per user per record. If user wants to change feedback, they UPDATE the existing row (upsert behavior).

2. **Snapshot fields**: Store the Gemini classification AT THE TIME of feedback. This is critical because:
   - Gemini's classification might change in future runs
   - We need to know what the user was looking at when they gave feedback
   - `clasificacionSnapshot` as JSON for flexibility

3. **Separate `corregido*` fields**: User's correction is explicit, typed fields (not just a text field). Enables future analytics on what corrections users commonly make.

4. **Cascade on delete**: If a ResultadoScraping is deleted, the feedback history is preserved in the `clasificaciones_feedback` table for analytics even if orphaned. Actually, should be `SET NULL` so feedback records still show WHO gave what feedback even if the article is deleted. **Reconsider: SET NULL** — the feedback metadata (who, when, what) is valuable even if source article gone.

---

## Proposed UI Flow

### Step-by-Step Flow

```
1. User sees a row with PEP/OPI classification badge
   └─ Row shows: [keyword] [PEP badge] [Nombre] [Relevante] [Descartar] [Ver análisis]

2. User clicks "✅ Correcto" or "❌ Incorrecto" button
   - Buttons appear ONLY on rows where gemini_analyzed = true
   - Permission check: user must have `dar feedback clasificaciones`
   - If user already gave feedback: buttons show their current feedback state (styled differently)

3a. If "Correcto" clicked:
   - Immediate feedback saved (no modal needed for correct)
   - Flash message: "Clasificación marcada como correcta"
   - Row briefly shows green checkmark indicator

3b. If "Incorrecto" clicked:
   - Modal opens (same pattern as "Ver análisis" modal)
   - Modal title: "Corregir Clasificación"
   - Shows current Gemini classification (read-only, snapshot)
   - Form fields:
     * Corrección: dropdown [PEP / OPI / No relevante]
     * Nombre (opcional): text input
     * Cargo (opcional): text input  
     * Motivo: textarea (required, why was it wrong?)
   - Buttons: [Cancelar] [Guardar Feedback]

4. User fills form and clicks "Guardar Feedback"
   - Validation: motivo required for incorrect, categoria required
   - Feedback saved (upsert — update if exists, insert if new)
   - Modal closes
   - Flash message: "Feedback guardado"
   - Row shows updated state (different badge color if feedback given)

5. User can later change their feedback
   - Clicking the same button again opens modal pre-filled with their previous feedback
   - Saving updates the existing row
```

### Visual States

| State | Badge/Indicator |
|-------|-----------------|
| No feedback given | Neutral buttons |
| User marked correcto | Green checkmark badge on row |
| User marked incorrect | Orange warning badge on row |
| Viewing own feedback | Modal pre-filled |

---

## Risks

1. **Duplicate feedback prevention**: Must handle case where user gives feedback, then changes their mind. Use upsert pattern — `firstOrCreate` by (resultado_id, usuario_id), then update.

2. **Race conditions**: If two analysts give feedback on the same record simultaneously. Solution: unique constraint on (resultado_scraping_id, usuario_id) at DB level prevents duplicates; Laravel catches the constraint violation and does update instead.

3. **Data integrity when ResultadoScraping is deleted**: My recommendation is `SET NULL` on `resultado_scraping_id` so we retain WHO gave what feedback. But the FK column becomes nullable and queries for "show feedback for article" need to handle nulls.

4. **Feedback without a clear "correct" answer**: User might say "incorrecto" but not provide a clear correction. Ensure `corregidoCategoria` is required but `corregidoNombre/Cargo` are optional.

5. **Metric aggregation queries**: The feedback table will grow. For dashboard metrics (precision rate = correct / total feedback), ensure proper indexes exist before dashboard implementation.

6. **Permission creep**: Should `operador` role also be able to give feedback? Currently operadores can `marcar leido` and `marcar relevante` — feedback seems like a reasonable extension. Recommend: all authenticated roles can give feedback (operador, supervisor, admin).

7. **Flash messages for inline actions**: The current row actions (marcarLeido, etc.) don't show flash messages — they just update silently. Feedback should probably show confirmation since it's more impactful.

---

## Scope Boundaries

### IN This Change:
- [ ] New `clasificaciones_feedback` table with migration
- [ ] New `ClasificacionFeedback` Eloquent model with relationships
- [ ] New `dar feedback clasificaciones` permission in RolesPermisosSeeder
- [ ] Feedback buttons in Resultados row (only on analyzed rows)
- [ ] Feedback modal in Resultados blade (expand existing modal infrastructure)
- [ ] `guardarFeedback()` and `actualizarFeedback()` methods in Resultados.php
- [ ] Upsert logic for feedback (one per user per record)
- [ ] Basic scopes on model: `scopeCorrecto()`, `scopeIncorrecto()`, `scopeByUsuario()`
- [ ] Tests: FeedbackTest covering upsert, permissions, modal display

### OUT of This Change (Future Changes):
- [ ] Dashboard metrics/aggregations (precision rate) — belongs to Dashboard change
- [ ] Adaptive confidence adjustment in Gemini prompt — belongs to AI/Prompt iteration change
- [ ] Feedback on PEP Monitor (cambios) — separate feature
- [ ] Bulk feedback operations — not needed yet
- [ ] Email/notification when classification is disputed — not needed yet
- [ ] Analytics views/reports — belongs to Dashboard change

---

## Integration Points Summary

| Component | Change |
|-----------|--------|
| `ResultadoScraping` model | Add `feedback()` relationship |
| `User` model | Add `clasificacionesFeedback()` relationship (or via results relationship) |
| `Resultados.php` | Add: `feedbackModalId`, `feedbackModalTipo`, `tiposFeedback`, `guardarFeedback()`, `toggleFeedbackCorrecto()` |
| `resultados.blade.php` | Add: feedback buttons per row (inside acciones cell), feedback modal (after Ver análisis modal) |
| `RolesPermisosSeeder` | Add `dar feedback clasificaciones` permission, assign to all roles |
| New: `database/migrations/xxxx_create_clasificaciones_feedback_table.php` | Full table as designed above |
| New: `app/Models/ClasificacionFeedback.php` | Model with all relationships and scopes |

---

**Status:** exploration-complete  
**Next Recommended:** sdd-propose (create formal change proposal with scope, approach, and rollback plan)