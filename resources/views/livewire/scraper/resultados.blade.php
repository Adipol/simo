<div class="space-y-4">

    {{-- Toolbar --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2 flex-wrap">
            <input wire:model.live.debounce.400ms="busqueda" type="text"
                placeholder="Buscar keyword, titulo, URL..."
                class="simo-input w-56" />

            <select wire:model.live="filtroPais" class="simo-select">
                <option value="">Todos los paises</option>
                @foreach($paises as $p)
                    <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
                @endforeach
            </select>

            <select wire:model.live="filtroCategoria" class="simo-select">
                <option value="">Todas las categorias</option>
                @foreach($categorias as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>

            <select wire:model.live="filtroLeido" class="simo-select">
                <option value="">Todos</option>
                <option value="0">Sin leer</option>
                <option value="1">Leidos</option>
            </select>

            <select wire:model.live="filtroRelevante" class="simo-select">
                <option value="">Todos</option>
                <option value="1">Relevantes</option>
                <option value="null">Sin clasificar</option>
            </select>

            <select wire:model.live="filtroDescartado" class="simo-select">
                <option value="0">Activos</option>
                <option value="1">Descartados</option>
                <option value="">Todos</option>
            </select>

            <select wire:model.live="filtroGemini" class="simo-select">
                <option value="">Gemini: Todos</option>
                <option value="pending">Sin analizar</option>
                <option value="pep">PEP confirmado</option>
                <option value="opi">OPI confirmado</option>
                <option value="not_pep">No relevante</option>
            </select>
        </div>

        <button wire:click="exportarCsv"
            class="simo-btn bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 shrink-0">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            CSV
        </button>
    </div>

    {{-- Banner descartados --}}
    @if($filtroDescartado === '1')
        <div class="flex items-center justify-between px-4 py-2.5 bg-amber-50 border border-amber-100 rounded-xl text-xs text-amber-700">
            <span>Mostrando articulos descartados. Usa "Restaurar" para devolverlos.</span>
            <button wire:click="$set('filtroDescartado', '0')" class="font-medium underline hover:no-underline">Volver a activos</button>
        </div>
    @endif

    {{-- Tabla --}}
    <div class="simo-card p-0 overflow-hidden">
        <table class="simo-table min-w-full">
            <thead>
                <tr>
                    <th style="width:42%">Keyword / Articulo</th>
                    <th>Sitio</th>
                    <th class="text-center" style="width:60px">Score</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($resultados as $r)
                    <tr class="{{ !$r->leido && !$r->descartado ? 'bg-indigo-50/30' : 'bg-white' }}">
                        <td>
                            <div class="flex items-start gap-2">
                                @if(!$r->leido && !$r->descartado)
                                    <span class="mt-1.5 w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0"></span>
                                @else
                                    <span class="mt-1.5 w-1.5 h-1.5 shrink-0"></span>
                                @endif
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-xs font-semibold text-indigo-600">{{ $r->keyword }}</span>
                                        @if($r->relevante)
                                            <span class="simo-badge bg-emerald-50 text-emerald-600" style="font-size:9px">relevante</span>
                                        @endif
                                        @if($r->descartado)
                                            <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200" style="font-size:9px">descartado</span>
                                        @endif
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
                                        {{-- Feedback badge (visible to all) --}}
                                        @php $fb = $r->feedback?->first(); @endphp
                                        @if($fb)
                                            @if($fb->tipo->value === 'correcto')
                                                <span class="simo-badge bg-emerald-50 text-emerald-600 border-emerald-100" style="font-size:9px">✓ fb</span>
                                            @else
                                                <span class="simo-badge bg-amber-50 text-amber-600 border-amber-100" style="font-size:9px">✗ fb</span>
                                            @endif
                                        @endif
                                    </div>
                                    @if($r->titulo)
                                        <p class="text-xs text-gray-700 mt-0.5 leading-snug">{{ Str::limit($r->titulo, 90) }}</p>
                                    @endif
                                    <a href="{{ $r->url }}" target="_blank"
                                       class="text-[10px] text-gray-400 hover:text-indigo-500 truncate block max-w-sm mt-0.5 transition-colors">
                                        {{ Str::limit($r->url, 75) }}
                                    </a>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="text-xs font-medium text-gray-700">{{ $r->sitio?->nombre ?? '—' }}</div>
                            <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                @if($r->pais)
                                    <span class="text-[10px] text-gray-400">{{ $r->pais }}</span>
                                @endif
                                @if($r->categoria)
                                    <span class="simo-badge {{ $r->categoria === 'PEP' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600' }}" style="font-size:9px">
                                        {{ $r->categoria }}
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="text-sm font-bold {{ $r->relevance_score >= 70 ? 'text-emerald-600' : ($r->relevance_score >= 40 ? 'text-amber-500' : 'text-gray-300') }}">
                                {{ $r->relevance_score }}
                            </span>
                            @if($r->found_in_title)
                                <div class="text-[9px] text-emerald-500 font-medium">titulo</div>
                            @endif
                        </td>
                        <td class="text-xs text-gray-500 whitespace-nowrap">
                            {{ $r->fecha_encontrado->format('d/m/y H:i') }}
                        </td>
                        <td>
                            <div class="flex items-center gap-1">
                                @if($r->descartado)
                                    <button wire:click="restaurar({{ $r->id }})"
                                        class="simo-btn bg-indigo-50 text-indigo-600 hover:bg-indigo-100">
                                        Restaurar
                                    </button>
                                @else
                                    @if(!$r->leido)
                                        <button wire:click="marcarLeido({{ $r->id }})"
                                            class="simo-btn-ghost text-gray-400">
                                            Leido
                                        </button>
                                    @endif
                                    <button wire:click="marcarRelevante({{ $r->id }}, true)"
                                        class="simo-btn {{ $r->relevante ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-50 text-gray-500 hover:bg-emerald-50 hover:text-emerald-600' }}">
                                        Relevante
                                    </button>
                                    <button wire:click="descartar({{ $r->id }})"
                                        wire:confirm="Descartar este articulo? No aparecera en la lista principal pero se puede recuperar."
                                        class="simo-btn-danger" title="Descartar">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                    @if($r->gemini_analyzed)
                                        <button wire:click="$set('verAnalisisId', {{ $r->id }})"
                                            class="simo-btn-ghost text-indigo-500 hover:text-indigo-600">
                                            Ver análisis
                                        </button>
                                        @can('dar feedback clasificaciones')
                                        @php $fb = $r->feedback?->first(); @endphp
                                        <button wire:click="guardarFeedbackCorrecto({{ $r->id }})"
                                            class="simo-btn text-xs {{ $fb && $fb->tipo->value === 'correcto' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-50 text-gray-500 hover:bg-emerald-50 hover:text-emerald-600' }}">
                                            ✓ Correcto
                                        </button>
                                        <button wire:click="abrirModalFeedbackIncorrecto({{ $r->id }})"
                                            class="simo-btn text-xs {{ $fb && $fb->tipo->value === 'incorrecto' ? 'bg-amber-100 text-amber-700' : 'bg-gray-50 text-gray-500 hover:bg-amber-50 hover:text-amber-600' }}">
                                            ✗ Incorrecto
                                        </button>
                                        @endcan
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-12 text-center text-sm text-gray-400">
                            Sin resultados para los filtros aplicados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-50">
            {{ $resultados->links() }}
        </div>
    </div>

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

    {{-- Modal Feedback Incorrecto --}}
    @if($feedbackModalId)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
        wire:click.self="cerrarModalFeedback">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">Corregir Clasificación</h2>
                <button wire:click="cerrarModalFeedback"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 text-lg">&times;</button>
            </div>
            <form wire:submit="guardarFeedbackIncorrecto" class="px-6 py-5 space-y-4">
                {{-- Categoria corregida (required) --}}
                <div>
                    <label class="simo-label">Categoría corregida *</label>
                    <select wire:model="feedbackCategoriaCorregida" class="simo-select w-full">
                        <option value="">Seleccionar...</option>
                        @foreach(\App\Enums\CategoriaCorreccion::cases() as $cat)
                            <option value="{{ $cat->value }}">{{ $cat->value }}</option>
                        @endforeach
                    </select>
                    @error('feedbackCategoriaCorregida') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>

                {{-- Motivo (required, min 10) --}}
                <div>
                    <label class="simo-label">Motivo de la corrección *</label>
                    <textarea wire:model="feedbackMotivo" rows="3" class="simo-input w-full" placeholder="Explica por qué la clasificación es incorrecta (mín. 10 caracteres)"></textarea>
                    @error('feedbackMotivo') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>

                {{-- Optional fields --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="simo-label">PEP / No PEP</label>
                        <select wire:model="feedbackIsPepCorregido" class="simo-select w-full">
                            <option value="">—</option>
                            <option value="1">PEP</option>
                            <option value="0">No PEP</option>
                        </select>
                    </div>
                    <div>
                        <label class="simo-label">Nombre corregido</label>
                        <input wire:model="feedbackNombreCorregido" type="text" class="simo-input w-full" />
                    </div>
                </div>

                <div>
                    <label class="simo-label">Cargo corregido</label>
                    <input wire:model="feedbackCargoCorregido" type="text" class="simo-input w-full" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="cerrarModalFeedback" class="simo-btn bg-gray-100 text-gray-600">Cancelar</button>
                    <button type="submit" class="simo-btn bg-indigo-600 text-white hover:bg-indigo-700">Guardar</button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
