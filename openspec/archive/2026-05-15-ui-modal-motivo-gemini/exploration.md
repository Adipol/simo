# Exploration: ui-modal-motivo-gemini

**Date**: 2026-05-15  
**Status**: success  
**Phase**: explore

---

## Current State

The route `/scraper/resultados` maps to `App\Livewire\Scraper\Resultados` (NOT `/admin/resultados-scraping` — that path doesn't exist in routes/web.php).

### Component: `app/Livewire/Scraper/Resultados.php`

- `$verAnalisisId: ?int` — public property, set to the result ID when "Ver análisis" is clicked via `wire:click="$set('verAnalisisId', {{ $r->id }})"`.
- `#[Computed] resultadoAnalisis(): ?ResultadoScraping` — lazy-loads the full `ResultadoScraping` model WITH `personas` relation (`orderByDesc('threshold_passed')->orderByDesc('confianza')`). The model already includes `gemini_motivo` (text, fillable, no cast needed — it's a plain string).

### View: `resources/views/livewire/scraper/resultados.blade.php`

Modal section (lines 242–291):

```blade
@if($verAnalisisId && $resultadoAnalisis)
<div ...> <!-- Fixed overlay -->
    <div ...> <!-- Modal card -->
        <div ...> <!-- Header: "Personas detectadas" title + close button -->
        <div class="px-6 py-4 overflow-y-auto"> <!-- Scrollable body -->
            @if($resultadoAnalisis->personas->isEmpty())
                <p ...>Sin personas detectadas</p>  ← BUG: motivo not shown here
            @else
                <div class="space-y-3">
                    @foreach($resultadoAnalisis->personas as $persona)
                        <!-- shows nombre, cargo, categoria, threshold badge, confianza, persona->motivo -->
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endif
```

**Root cause**: The `@if(isEmpty)` branch shows only the "Sin personas detectadas" message and ignores `$resultadoAnalisis->gemini_motivo` entirely. The `@else` branch (personas loop) also doesn't show `gemini_motivo`. So `gemini_motivo` is NEVER shown in the modal.

### Data already available

`ResultadoScraping::$fillable` includes `gemini_motivo` (line 28).  
`resultadoAnalisis` computed property already loads the full model via `::find()` — `gemini_motivo` is already in-memory when the modal renders. **No new DB query needed.**

### Open/close mechanism

Pure Livewire: `wire:click="$set('verAnalisisId', ...)"` to open, same with `null` to close. No Alpine required, no JS events. The modal renders server-side via `@if($verAnalisisId && $resultadoAnalisis)`.

---

## Affected Areas

- `resources/views/livewire/scraper/resultados.blade.php` — modal section (lines 242–291), add `gemini_motivo` display
- `tests/Feature/Livewire/Scraper/ResultadosModalTest.php` — add 2 new tests (motivo shown when present, motivo hidden when null)
- No PHP class changes needed — model data already flows to view

---

## Approaches

### Option A — Minimal patch (recommended)

Add `gemini_motivo` display to the Blade modal ABOVE the personas list (always visible when present), using the same gray-background pill style as `$persona->motivo`.

```blade
{{-- Motivo Gemini --}}
@if($resultadoAnalisis->gemini_motivo)
    <div class="mb-4 bg-gray-50 rounded-xl px-4 py-3">
        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Razonamiento Gemini</p>
        <p class="text-xs text-gray-600 leading-relaxed">{{ $resultadoAnalisis->gemini_motivo }}</p>
    </div>
@endif
```

Place this **before** the `@if(isEmpty)` / `@else` block, so it shows regardless of persona count.

- Pros: zero PHP changes, zero DB changes, pure Blade, consistent with existing persona->motivo style, motivo visible for ALL cases (0 personas AND 1+ personas)
- Cons: none
- Effort: Low (~15 min implementation + 20 min tests)

### Option B — Add dedicated Livewire property `$motivoSeleccionado`

Add a `public ?string $motivoSeleccionado = null;` property, load it alongside `verAnalisisId`. Expose it via a dedicated method instead of using `resultadoAnalisis`.

- Pros: slightly more explicit component API
- Cons: unnecessary — `resultadoAnalisis` already loads the full model; adds complexity for zero benefit
- Effort: Medium

---

## Recommendation

**Option A**. The data is already in memory via `resultadoAnalisis`. This is a pure Blade addition — no component PHP changes, no service changes, no new DB queries.

Position: show `gemini_motivo` at the TOP of the modal body, before personas. This makes it the "Gemini's conclusion" framing, and operators can read it first regardless of whether personas were found.

---

## Test Plan (TDD order)

New tests in `tests/Feature/Livewire/Scraper/ResultadosModalTest.php`:

```
test_modal_shows_gemini_motivo_when_present()
  → Create resultado with gemini_motivo = "El artículo menciona..."
  → set verAnalisisId
  → assertSee("El artículo menciona...")
  → assertSee("Razonamiento Gemini")

test_modal_hides_motivo_section_when_null()
  → Create resultado with gemini_motivo = null
  → set verAnalisisId
  → assertDontSee("Razonamiento Gemini")
```

Existing tests must stay green — `test_modal_handles_zero_personas` still passes because "Sin personas detectadas" text still appears.

**400-line budget**: ~20 lines Blade + ~40 lines tests = ~60 lines total. Well within budget. Single PR.

---

## Risks

1. **Motivo may be very long** — Gemini sometimes writes verbose explanations. The scrollable modal body (`overflow-y-auto`) handles this, but we should not truncate (operators need the full text for validation).
2. **Null handling** — `gemini_motivo` is nullable in the DB. The `@if` guard handles this correctly.
3. **No new risks** — this is additive-only; no existing behavior is modified.

---

## Ready for Proposal

Yes — scope is minimal, approach is clear, tests identified. Can proceed directly to `sdd-propose`.
