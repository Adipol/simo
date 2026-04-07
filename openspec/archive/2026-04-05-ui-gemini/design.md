# Design: UI Gemini Integration

## Technical Approach

Add Gemini analysis display to existing Livewire components using established patterns. Minimal changes: new filter property + existing modal pattern + conditional blade sections. No major refactors, just additive UI elements that follow the `simo-*` CSS class conventions.

## Architecture Decisions

### Decision: Modal vs Expandable Row

**Choice**: Modal (using existing `<x-modal>` pattern)
**Alternatives considered**: Expandable row section
**Rationale**: Consistent with existing patterns in `sitios.blade.php`. Modal keeps table rows compact, works well on mobile, and allows longer `gemini_motivo` text to display comfortably.

### Decision: Confidence Display

**Choice**: Numeric badge + color coding (no progress bar)
**Alternatives considered**: Progress bar
**Rationale**: Existing `relevance_score` uses numeric with color. Keep consistency. Progress bar adds visual noise for a 0-100 value.

### Decision: Filter Implementation

**Choice**: Single `filtroGemini` property with string values
**Alternatives considered**: Multiple boolean filters
**Rationale**: Matches existing filter pattern (`filtroLeido`, `filtroRelevante`). Keeps query builder logic centralized in `buildQuery()`.

## Data Flow

```
Browser (filter change) → Livewire (updatingFiltroGemini) → resetPage() → buildQuery() → DB query with gemini_analyzed filter
Browser (click "Ver análisis") → Livewire ($verAnalisisId) → Modal shows → $resultadoAnalisis computed
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Livewire/Scraper/Resultados.php` | Modify | Add `filtroGemini`, `$verAnalisisId`, `$resultadoAnalisis` computed, CSV export columns |
| `resources/views/livewire/scraper/resultados.blade.php` | Modify | Add Gemini filter, Gemini badge section, Ver análisis button, modal |
| `app/Livewire/Pep/Cambios.php` | Modify | Add `$verAnalisisId`, `$cambioAnalisis` computed |
| `resources/views/livewire/pep/cambios.blade.php` | Modify | Add Gemini analysis section in diff panel, MAE badge |

## Interfaces / Contracts

### Resultados.php — New Properties

```php
// Filter property
public string $filtroGemini = ''; // ''|'pending'|'pep'|'opi'|'not_pep'

// Modal state
public ?int $verAnalisisId = null;

// Computed property for modal data
#[Computed]
public function resultadoAnalisis(): ?ResultadoScraping
```

### Resultados.php — buildQuery Addition

```php
// After line 151, before return
if ($this->filtroGemini === 'pending') {
    $q->where('gemini_analyzed', false);
} elseif ($this->filtroGemini === 'pep') {
    $q->where('gemini_analyzed', true)->where('gemini_is_pep', true)->where('gemini_categoria', 'PEP');
} elseif ($this->filtroGemini === 'opi') {
    $q->where('gemini_analyzed', true)->where('gemini_is_pep', true)->where('gemini_categoria', 'OPI');
} elseif ($this->filtroGemini === 'not_pep') {
    $q->where('gemini_analyzed', true)->where('gemini_is_pep', false);
}
```

### Cambios.php — New Properties

```php
// Modal state (reuse existing verDiffId pattern)
// No new property needed — use existing verDiffId

// Accessor for JSON analysis
// In Cambio model, add:
public function getGeminiAnalisisAttribute(): ?array
{
    return $this->gemini_analisis_json;
}
```

## Blade Template Changes

### resultados.blade.php — Filter Section (after line 40)

```blade
<select wire:model.live="filtroGemini" class="simo-select">
    <option value="">Todos</option>
    <option value="pending">Sin analizar</option>
    <option value="pep">PEP confirmado</option>
    <option value="opi">OPI confirmado</option>
    <option value="not_pep">No relevante</option>
</select>
```

### resultados.blade.php — Badge Section (inside keyword div, after line 90)

```blade
{{-- Gemini analysis badge --}}
@if(!$r->gemini_analyzed)
    <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200" style="font-size:9px">Pendiente</span>
@elseif($r->gemini_is_pep)
    <span class="simo-badge {{ $r->gemini_categoria === 'PEP' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600' }}" style="font-size:9px">
        {{ $r->gemini_categoria }}
    </span>
    @if($r->gemini_nombre)
        <span class="text-[10px] text-gray-600">{{ Str::limit($r->gemini_nombre, 30) }}</span>
    @endif
@else
    <span class="simo-badge bg-zinc-100 text-zinc-400" style="font-size:9px">No relevante</span>
@endif
```

### resultados.blade.php — Action Button (in actions cell, after line 151)

```blade
@if($r->gemini_analyzed)
    <button wire:click="$set('verAnalisisId', {{ $r->id }})"
        class="simo-btn-ghost text-indigo-500 hover:text-indigo-600">
        Ver análisis
    </button>
@endif
```

### resultados.blade.php — Modal (after table, before closing div)

```blade
{{-- Modal Análisis Gemini --}}
@if($verAnalisisId && $resultadoAnalisis)
<div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
    wire:click.self="$set('verAnalisisId', null)">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Análisis Gemini</h2>
            <button wire:click="$set('verAnalisisId', null)"
                class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 text-lg">&times;</button>
        </div>
        <div class="px-6 py-5 space-y-3">
            <div class="grid grid-cols-2 gap-4">
                <div><p class="text-xs text-gray-500">Nombre</p><p class="text-sm font-medium text-gray-800">{{ $resultadoAnalisis->gemini_nombre ?? '—' }}</p></div>
                <div><p class="text-xs text-gray-500">Cargo</p><p class="text-sm font-medium text-gray-800">{{ $resultadoAnalisis->gemini_cargo ?? '—' }}</p></div>
                <div><p class="text-xs text-gray-500">Categoría</p><p class="text-sm"><span class="simo-badge {{ $resultadoAnalisis->gemini_categoria === 'PEP' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600' }}">{{ $resultadoAnalisis->gemini_categoria }}</span></p></div>
                <div><p class="text-xs text-gray-500">Confianza</p><p class="text-sm font-medium {{ $resultadoAnalisis->gemini_confianza >= 70 ? 'text-emerald-600' : 'text-amber-600' }}">{{ $resultadoAnalisis->gemini_confianza }}%</p></div>
            </div>
            <div><p class="text-xs text-gray-500 mb-1">Motivo</p><p class="text-sm text-gray-700 bg-gray-50 rounded-lg p-3">{{ $resultadoAnalisis->gemini_motivo }}</p></div>
        </div>
    </div>
</div>
@endif
```

### cambios.blade.php — Gemini Section (in diff panel, after posibles_peps section, before diff_texto)

```blade
{{-- Análisis Gemini --}}
@if($cambioDetalle->gemini_analyzed && $cambioDetalle->gemini_analisis_json)
    @php $analisis = $cambioDetalle->gemini_analisis_json; @endphp
    <div class="px-5 py-3 bg-indigo-50/60 border-b border-indigo-100">
        <p class="text-xs font-semibold text-indigo-700 mb-2">Análisis Gemini</p>
        <div class="grid grid-cols-2 gap-3 text-xs">
            @if($analisis['persona_removida'] ?? null)
                <div><span class="text-gray-500">Removido:</span> <span class="font-medium text-gray-800">{{ $analisis['persona_removida'] }}</span></div>
            @endif
            @if($analisis['persona_nueva'] ?? null)
                <div><span class="text-gray-500">Nuevo:</span> <span class="font-medium text-gray-800">{{ $analisis['persona_nueva'] }}</span></div>
            @endif
            <div><span class="text-gray-500">Cargo:</span> <span class="font-medium">{{ $analisis['cargo'] ?? '—' }}</span></div>
            <div class="flex items-center gap-2">
                @if($analisis['es_mae'] ?? false)
                    <span class="simo-badge bg-red-100 text-red-700 border-red-200">MAE</span>
                @else
                    <span class="simo-badge bg-gray-100 text-gray-500">No MAE</span>
                @endif
                @php
                    $riesgoColors = ['alto' => 'bg-red-50 text-red-600', 'medio' => 'bg-amber-50 text-amber-600', 'bajo' => 'bg-emerald-50 text-emerald-600'];
                    $riesgo = $analisis['riesgo'] ?? 'bajo';
                @endphp
                <span class="simo-badge {{ $riesgoColors[$riesgo] ?? $riesgoColors['bajo'] }}">Riesgo: {{ ucfirst($riesgo) }}</span>
            </div>
        </div>
        @if($analisis['analisis'] ?? null)
            <p class="text-xs text-gray-600 mt-2 bg-white rounded p-2">{{ $analisis['analisis'] }}</p>
        @endif
    </div>
@endif
```

### cambios.blade.php — MAE Badge in Card Header (after posibles_peps badge, line 35)

```blade
{{-- MAE Badge if Gemini detected it --}}
@php $analisis = $c->gemini_analisis_json; @endphp
@if($analisis['es_mae'] ?? false)
    <span class="simo-badge bg-red-100 text-red-700 border-red-200">MAE</span>
@endif
```

## CSV Export Changes

### Resultados.php — exportarCsv() method (update header and data rows)

```php
// Line 95: Add Gemini columns to header
fputcsv($handle, ['ID', 'Keyword', 'URL', 'Sitio', 'Pais', 'Categoria', 'Titulo', 'Contexto', 'Relevance', 'Fecha', 'Gemini_Analizado', 'Gemini_PEP', 'Gemini_Categoria', 'Gemini_Nombre', 'Gemini_Cargo', 'Gemini_Confianza']);

// Lines 98-110: Add Gemini fields to data row
fputcsv($handle, [
    $r->id,
    $r->keyword,
    $r->url,
    $r->sitio?->nombre ?? '',
    $r->pais,
    $r->categoria ?? '',
    $r->titulo ?? '',
    $r->contexto ?? '',
    $r->relevance_score,
    $r->fecha_encontrado->format('Y-m-d H:i:s'),
    $r->gemini_analyzed ? 'Si' : 'No',
    $r->gemini_is_pep ? 'Si' : 'No',
    $r->gemini_categoria ?? '',
    $r->gemini_nombre ?? '',
    $r->gemini_cargo ?? '',
    $r->gemini_confianza ?? '',
]);
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `buildQuery()` filter logic | Test each filtroGemini value returns correct query |
| Unit | `resultadoAnalisis` computed | Test returns null when no ID, returns model when ID set |
| Feature | Filter persistence across pagination | Livewire test: set filter → paginate → assert filter retained |
| Feature | Modal open/close | Livewire test: click Ver análisis → modal appears → click outside → modal closes |
| Visual | Badge colors match spec | Browser: PEP=indigo, OPI=amber, Pendiente=gray |

## Migration / Rollout

No migration required — UI-only change. All Gemini columns already exist in DB from backend integration.

## Open Questions

- [ ] Should "Ver análisis" button be available for records where `gemini_is_pep = false`? Currently yes (shows "No relevante" in modal).
- [ ] Confidence threshold for color: currently using ≥70 for green, <70 for amber. Matches existing `relevance_score` pattern.