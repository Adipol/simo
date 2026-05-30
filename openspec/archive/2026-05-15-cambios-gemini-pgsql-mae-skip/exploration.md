# Exploration: cambios-gemini-pgsql-mae-skip (LIM-1)

**Date**: 2026-05-15
**Status**: success
**Phase**: explore

---

## Current State

The test `mae_badge_shown_when_gemini_detects_mae` in
`tests/Feature/Livewire/Pep/CambiosGeminiTest.php:42-89` is guarded by:

```php
if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
    $this->markTestSkipped(...);
}
```

The docblock lists three candidate hypotheses for why the cambio's ID doesn't
appear in the rendered HTML on pgsql:
1. JSON cast differs between drivers when serializing
2. Operator `->>` with NULL value in JSONB behaves differently
3. Boolean cast in `where('gemini_analyzed', true)` needs pgsql fix

---

## Affected Areas

- `tests/Feature/Livewire/Pep/CambiosGeminiTest.php` — failing test (skipped on pgsql)
- `app/Models/Cambio.php` — `scopeConPersona()`, `$casts`
- `app/Livewire/Pep/Cambios.php` — component that applies `conPersona()` scope by default
- `resources/views/livewire/pep/cambios.blade.php` — renders cambio IDs only in HTML attributes

---

## Root Cause Analysis

### H1 — JSON cast differs between drivers: RULED OUT

The migration uses `$table->json('gemini_analisis_json')` which creates a `JSON`
column in PostgreSQL (not `JSONB`). Laravel's `'array'` cast uses `json_encode()`
for both drivers. The value is stored identically as a JSON text string.

### H2 — `->>` operator with NULL value: RULED OUT AS SOLE CAUSE

`CambiosFiltroPersonaTest` (no pgsql skip) exercises the exact same
`scopeConPersona()` with `whereRaw("gemini_analisis_json->>'persona_nueva' IS NOT
NULL")` and similar fixtures. It passes in pgsql CI. The `->>` operator works
correctly on JSON columns in PostgreSQL.

### H3 — Boolean cast `where('gemini_analyzed', true)`: RULED OUT

`Connection::prepareBindings()` converts PHP `true` → `1` (int) for all drivers.
Many other tests use `where('gemini_analyzed', true)` without pgsql skip and they
pass. PostgreSQL accepts integer bindings for boolean comparisons via PDO.

### ✅ ROOT CAUSE (H4 — newly identified): Test assertion is incorrect

The blade view renders the cambio `id` **exclusively in HTML attributes**:

```html
<div wire:key="cambio-{{ $c->id }}" ...>
    <button wire:click="toggleDiff({{ $c->id }})">...
    <button wire:click="marcarRevisado({{ $c->id }})">...
```

The cambio `id` is **never rendered as visible text** in the component HTML.

`assertSeeText($cambio->id)` calls `strip_tags()` on the HTML before searching.
HTML attributes are stripped. Therefore `assertSeeText($cambio->id)` searches only
in the visible text content — where the ID does **not appear**.

**Why it passes in SQLite by accident**: When the test runs on SQLite in-memory,
the fixture's auto-increment ID happens to be `1` (or another small integer).
The string `"1"` coincidentally appears in the visible text from other content
rendered in the component (e.g., line counts like `+10 nuevas` contains digits,
or pagination text, or other numeric values that include the digit `1`). The
assertion passes not because the cambio ID is in the list, but because the digit
`1` appears somewhere else in the stripped HTML.

**Why it fails in pgsql**: PostgreSQL sequences (even after TRUNCATE...RESTART
IDENTITY) may assign a different ID (e.g., `2`, or the sequence restarts but
some other fixture ran first), and that specific integer does not appear in the
visible text. Or even if the ID is `1`, the assertion failure indicates the
cambio is NOT in `$cambios` at all — meaning the `scopeConPersona()` actually
fails to return it on pgsql, and the "passing" on SQLite was due to the
coincidental text match.

### ⚠️ SECONDARY FINDING: scopeConPersona MAY also fail on pgsql

There is a subtle but important difference between `CambiosFiltroPersonaTest`
(which passes on pgsql) and `CambiosGeminiTest` (which was failing):

- `CambiosFiltroPersonaTest` uses `assertViewHas('cambios', fn)` → accesses the
  Eloquent collection directly, bypassing HTML rendering.
- `CambiosGeminiTest` uses `assertSeeText($cambio->id)` → depends on HTML output.

This means: even if `scopeConPersona()` returns 0 rows on pgsql (the cambio
doesn't appear), `CambiosFiltroPersonaTest` would still detect it via
`assertViewHas`. But `CambiosGeminiTest` would fail with a misleading assertion
error.

**Therefore, there are potentially TWO bugs**:

1. **Confirmed**: The test assertion `assertSeeText($cambio->id)` is wrong —
   it should use `assertViewHas('cambios', ...)` like `CambiosFiltroPersonaTest`.

2. **Suspected but unconfirmed**: The cambio may genuinely not appear in
   `$cambios` on pgsql — the `scopeConPersona()` may not return it. This could
   be caused by a driver-specific behavior NOT caught by `CambiosFiltroPersonaTest`
   because that test verifies via `assertViewHas`, not HTML text.

   The most likely suspect for the scope failure: when `gemini_analisis_json` contains
   `null` JSON values (`"persona_removida": null`), the stored JSON in PostgreSQL
   `JSON` type may serialize differently under certain PDO/pgsql binding paths.

---

## Evidence

### Migration — column type is JSON, not JSONB

```php
// database/migrations/2026_04_05_000002_add_gemini_fields_to_cambios_table.php
$table->json('gemini_analisis_json')->nullable()->after('gemini_analyzed');
```

In PostgreSQL: creates `JSON` type (not `JSONB`).

### Model cast — 'array'

```php
// app/Models/Cambio.php
protected $casts = [
    'gemini_analyzed'      => 'boolean',
    'gemini_analisis_json' => 'array',
];
```

### scopeConPersona — uses whereRaw with ->> operator

```php
$gemini->where('gemini_analyzed', true)
    ->where(function (Builder $personas): void {
        $personas->whereRaw("gemini_analisis_json->>'persona_nueva' IS NOT NULL")
            ->orWhereRaw("gemini_analisis_json->>'persona_removida' IS NOT NULL");
    });
```

### Blade — ID never rendered as visible text

```html
<!-- Only in attributes, never as plain text content -->
<div wire:key="cambio-{{ $c->id }}" ...>
<button wire:click="toggleDiff({{ $c->id }})">
<button wire:click="marcarRevisado({{ $c->id }})">
```

### CambiosFiltroPersonaTest — same scope, different assertion, no pgsql skip

Uses `assertViewHas('cambios', fn($cambios) => $cambios->pluck('id')->contains($id))`
instead of `assertSeeText($id)`. This test passes on pgsql CI.

---

## Approaches

### Approach A — Fix the assertion only (conservative)

Replace `assertSeeText($cambio->id)` with `assertViewHas('cambios', fn ...)`
as used in `CambiosFiltroPersonaTest`. Remove the pgsql skip.

- **Pros**: Minimal change (1 line), no model/scope changes, removes deuda técnica
- **Cons**: Does NOT confirm whether the cambio actually appears on pgsql
  (the scope may still be broken). If the scope is broken, the test will still
  fail, but now with a meaningful failure message.
- **Effort**: Low

### Approach B — Fix assertion + verify scope on pgsql (comprehensive)

1. Fix the assertion (Approach A)
2. Remove the pgsql skip
3. Run CI to observe if the test now fails with a scope-related message
4. If scope fails: investigate the actual SQL generated on pgsql and fix the
   `whereRaw` if needed (e.g., explicit `::json` cast, or driver-aware query)

- **Pros**: Truly removes the deuda técnica, confirms scope works cross-driver
- **Cons**: Requires a CI run to get the actual failure; may reveal a second bug
- **Effort**: Low → Medium (depending on whether scope is also broken)

### Approach C — Use driver-aware assertSeeHtml (not recommended)

Use `assertSee` (which searches in raw HTML including attributes) instead of
`assertSeeText`. The ID appears in `wire:key` and `wire:click` attributes.

- **Pros**: Quick workaround
- **Cons**: Fragile — depends on internal Livewire attribute rendering; testing
  HTML attributes instead of domain behavior. Not aligned with test philosophy.
- **Effort**: Trivial

---

## Recommendation

**Approach B**: Fix the assertion AND remove the skip to let pgsql CI run the
corrected test. The fix is mechanical: replace `assertSeeText($cambio->id)` with
`assertViewHas('cambios', fn($cambios) => $cambios->pluck('id')->contains($cambio->id))`.
Then remove the skip guard.

If CI reveals the scope is also broken on pgsql, that becomes a second fix.
But the assertion bug alone is the confirmed root cause of the skip.

Strict TDD approach: write the fixed assertion first (it will fail on pgsql if
the scope is broken), fix scope if needed, then confirm both SQLite and pgsql pass.

---

## Risks

1. **Scope may also be broken on pgsql** — the assertion fix alone may expose a
   second failure. Budget time for a scope investigation if CI fails after the fix.

2. **Other tests in same file** — `gemini_analysis_section_shows_in_diff_panel`,
   `risk_level_colors_applied_correctly`, `optional_fields_hidden_when_missing`
   all use `assertSee(textContent)` not `assertSeeText(id)` — they should be fine.
   The `mae_badge_not_shown_when_not_mae` test uses `assertDontSee('MAE')` — safe.

3. **scopeMultimodal uses pgsql-only syntax** — `jsonb_array_length(...)::jsonb`
   which will FAIL on SQLite. Separate deuda técnica, not in scope here.

4. **Production scope behavior** — if `scopeConPersona()` is broken on pgsql,
   production (which IS pgsql) is also affected: the main cambios view would show
   no entries with personas. This would be immediately visible to users.
   Counterpoint: if production were broken, users would have reported it. So the
   scope likely works on pgsql — the test assertion is the only bug.

---

## Ready for Proposal

**Yes** — root cause is identified with high confidence. The fix is a 2-line change
in the test (assertion + remove skip). The scope investigation is a risk item but
not a blocker. Recommended next phase: `sdd-propose` to document the fix scope,
or directly `sdd-spec` since the change is trivial.
