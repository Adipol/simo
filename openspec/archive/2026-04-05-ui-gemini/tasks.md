# Tasks: UI Gemini Integration

## Phase 1: Resultados Component

### Task 1.1: Add filter property and computed to Resultados.php
**File**: `app/Livewire/Scraper/Resultados.php`

- [x] Add `public string $filtroGemini = ''` property (line ~40, after `filtroLeido`)
- [x] Add `public ?int $verAnalisisId = null` property
- [x] Add `#[Computed] public function resultadoAnalisis(): ?ResultadoScraping` method
- [x] Update `buildQuery()` method — add filter logic after line 151:
  ```php
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

### Task 1.2: Add Gemini filter select to resultados.blade.php
**File**: `resources/views/livewire/scraper/resultados.blade.php`

- [x] Add Gemini filter `<select>` after line 40 (after existing filter section)
  ```blade
  <select wire:model.live="filtroGemini" class="simo-select">
      <option value="">Todos</option>
      <option value="pending">Sin analizar</option>
      <option value="pep">PEP confirmado</option>
      <option value="opi">OPI confirmado</option>
      <option value="not_pep">No relevante</option>
  </select>
  ```

### Task 1.3: Add Gemini badge section inside keyword div
**File**: `resources/views/livewire/scraper/resultados.blade.php`

- [x] Add badge section after line 90 (inside keyword div, after relevance badge):
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

### Task 1.4: Add "Ver análisis" button in actions cell
**File**: `resources/views/livewire/scraper/resultados.blade.php`

- [x] Add button after line 151 (in actions cell):
  ```blade
  @if($r->gemini_analyzed)
      <button wire:click="$set('verAnalisisId', {{ $r->id }})"
          class="simo-btn-ghost text-indigo-500 hover:text-indigo-600">
          Ver análisis
      </button>
  @endif
  ```

### Task 1.5: Add modal for displaying analysis
**File**: `resources/views/livewire/scraper/resultados.blade.php`

- [x] Add modal after table, before closing div (after line ~165):
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

### Task 1.6: Update CSV export with Gemini columns
**File**: `app/Livewire/Scraper/Resultados.php`

- [x] Update `exportarCsv()` header (line ~95) to include: `Gemini_Analizado`, `Gemini_PEP`, `Gemini_Categoria`, `Gemini_Nombre`, `Gemini_Cargo`, `Gemini_Confianza`
- [x] Update data rows (lines ~98-110) to include Gemini fields

---

## Phase 2: Cambios Component

### Task 2.1: Add gemini_analisis_json accessor to Cambio model
**File**: `app/Models/Cambio.php`

- [x] Add accessor:
  ```php
  public function getGeminiAnalisisAttribute(): ?array
  {
      return $this->gemini_analisis_json;
  }
  ```

### Task 2.2: Add Gemini analysis section in diff panel
**File**: `resources/views/livewire/pep/cambios.blade.php`

- [x] Add Gemini section after posibles_peps section, before diff_texto (after line ~160):
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

### Task 2.3: Add MAE badge in card header
**File**: `resources/views/livewire/pep/cambios.blade.php`

- [x] Add MAE badge after posibles_peps badge (line ~35):
  ```blade
  {{-- MAE Badge if Gemini detected it --}}
  @php $analisis = $c->gemini_analisis_json; @endphp
  @if($analisis['es_mae'] ?? false)
      <span class="simo-badge bg-red-100 text-red-700 border-red-200">MAE</span>
  @endif
  ```

---

## Phase 3: Testing

### Task 3.1: Unit test for buildQuery() filter logic
**File**: `tests/Unit/Livewire/Scraper/ResultadosTest.php` (create if not exists)

- [x] Test `filtroGemini = ''` returns all results
- [x] Test `filtroGemini = 'pending'` returns only unanalyzed
- [x] Test `filtroGemini = 'pep'` returns only PEP confirmed
- [x] Test `filtroGemini = 'opi'` returns only OPI confirmed
- [x] Test `filtroGemini = 'not_pep'` returns only non-PEP analyzed

### Task 3.2: Unit test for resultadoAnalisis computed property
**File**: `tests/Unit/Livewire/Scraper/ResultadosTest.php`

- [x] Test returns null when `$verAnalisisId` is null
- [x] Test returns correct model when `$verAnalisisId` is set to existing ID
- [x] Test returns null when `$verAnalisisId` points to non-existent record

### Task 3.3: Feature test for filter persistence
**File**: `tests/Feature/Livewire/Scraper/ResultadosFilterTest.php` (create if not exists)

- [x] Test: set filter → paginate → assert filter retained in component state
- [x] Test: set filter → navigate away → return → assert filter retained in component state
- [x] Test: filter resets pagination when changed

### Task 3.4: Feature test for modal open/close
**File**: `tests/Feature/Livewire/Scraper/ResultadosModalTest.php` (create if not exists)

- [x] Test: click "Ver análisis" → modal appears with correct data
- [x] Test: click close button → modal closes
- [x] Test: click backdrop → modal closes
- [x] Test: modal not rendered when verAnalisisId is null
- [x] Test: unanalyzed result does not show "Ver análisis" button

---

## Phase 4: Cambios Component Testing (Warning Fixes)

### Task 4.1: Test Cambios Gemini section
**File**: `tests/Feature/Livewire/Pep/CambiosGeminiTest.php` (created)

- [x] Test: MAE badge shown when `gemini_analisis_json.es_mae = true`
- [x] Test: MAE badge not shown when `es_mae = false`
- [x] Test: Gemini analysis section shows in diff panel with all fields
- [x] Test: Risk level colors applied correctly (alto/medio/bajo)
- [x] Test: Gemini section hidden when `gemini_analyzed = false`
- [x] Test: Optional fields hidden when missing (persona_removida, persona_nueva)

### Task 4.2: Remove dead accessor code
**File**: `app/Models/Cambio.php`

- [x] Removed `getGeminiAnalisisAttribute()` accessor (blade accesses `gemini_analisis_json` directly via cast)

### Task 4.3: Create delta spec
**File**: `openspec/changes/ui-gemini/specs/spec.md`

- [x] Created formal delta spec with requirements REQ-001 through REQ-008
- [x] Test coverage matrix documented

---

## Notes

- No migration needed — all Gemini columns already exist in DB from backend integration
- Confidence threshold for color: ≥70 = green (emerald), <70 = amber
- Modal pattern follows existing `<x-modal>` convention from `sitios.blade.php`
- Badge colors: PEP = indigo, OPI = amber, Pendiente = gray, No relevante = gray
- Computed property `resultadoAnalisis` must be passed explicitly to view in `render()` (Livewire 4 behavior)
- Tests require `Queue::fake()` + `config(['services.gemini.enabled' => false])` to prevent observer from calling Gemini API during test execution
