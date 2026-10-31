<div class="simo-evidence-page">

    {{-- Header: title + pending badge --}}
    <header class="space-y-2">
    <div class="flex items-center gap-3">
        <h1 class="text-lg font-semibold text-zinc-900">Resultados Scraper</h1>
        @if($this->pendingCount > 0)
            <span class="simo-badge bg-amber-50 text-amber-600 border-amber-200 text-[9px]">
                {{ $this->pendingCount }} procesando
            </span>
        @endif
    </div>

    <p class="text-sm text-zinc-600">Ver los grupos registrados sin perder los títulos originales.</p>
    </header>

    {{-- Toolbar --}}
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-2 flex-wrap">
            <input wire:model.live.debounce.400ms="busqueda" type="text"
                aria-label="Buscar resultados" placeholder="Buscar keyword, titulo, URL..."
                class="simo-input w-56" />

            <select aria-label="País" wire:model.live="filtroPais" class="simo-select">
                <option value="">Todos los paises</option>
                @foreach($paises as $p)
                    <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
                @endforeach
            </select>

            <select aria-label="Categoría" wire:model.live="filtroCategoria" class="simo-select">
                <option value="">Todas las categorias</option>
                @foreach($categorias as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>

            <select aria-label="Lectura" wire:model.live="filtroLeido" class="simo-select">
                <option value="">Todos</option>
                <option value="0">Sin leer</option>
                <option value="1">Leidos</option>
            </select>

            <select aria-label="Relevancia" wire:model.live="filtroRelevante" class="simo-select">
                <option value="">Todos</option>
                <option value="1">Relevantes</option>
                <option value="null">Sin clasificar</option>
            </select>

            <select aria-label="Descarte" wire:model.live="filtroDescartado" class="simo-select">
                <option value="0">Activos</option>
                <option value="1">Descartados</option>
                <option value="">Todos</option>
            </select>

            <select aria-label="Archivo" wire:model.live="filtroArchivado" class="simo-select">
                <option value="0">No archivados</option>
                <option value="1">Archivados</option>
                <option value="">Todos</option>
            </select>

            <select aria-label="Análisis Gemini" wire:model.live="filtroGemini" class="simo-select">
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

    {{-- Banner archivados --}}
    @if($filtroArchivado === '1')
        <div class="flex items-center justify-between px-4 py-2.5 bg-sky-50 border border-sky-100 rounded-xl text-xs text-sky-700">
            <span>Mostrando articulos archivados. Usa "Desarchivar" para devolverlos a la vista principal.</span>
            <button wire:click="$set('filtroArchivado', '0')" class="font-medium underline hover:no-underline">Volver a activos</button>
        </div>
    @endif

    <aside class="simo-evidence-notice">
        <p class="text-sm font-semibold">Conservar la bandeja, abrir las fuentes.</p>
        <p class="text-xs text-zinc-600">Expanda los registros de cada grupo o use «Comparar fuentes» para revisar la evidencia. Abrir y cerrar no marca como leído ni modifica datos.</p>
    </aside>

    <section id="results-table" class="simo-evidence-table-card" aria-labelledby="table-title">
        <header class="simo-evidence-table-heading">
            <h2 id="table-title" class="text-base font-semibold">Artículos y grupos registrados</h2>
            <span class="simo-badge bg-zinc-100 text-zinc-600">{{ $resultados->total() }} resultados</span>
        </header>
        <div class="simo-evidence-table-scroll" role="region" aria-label="Tabla de resultados, desplazable" tabindex="0">
            <table class="simo-evidence-table" data-testid="results-table">
                <caption>Los grupos reflejan relaciones almacenadas, no eventos validados. Se conservan los títulos originales.</caption>
                <thead><tr>
                    <th scope="col">Keyword / Artículo</th><th scope="col">Sitio</th><th scope="col">Score</th><th scope="col">Fecha</th><th scope="col">Acciones</th>
                </tr></thead>
                <tbody>
                    @forelse($resultados as $r)
                        <tr wire:key="resultado-{{ $r->id }}" class="{{ $seleccionado?->id === $r->id ? 'simo-evidence-row-selected' : '' }}">
                            <td>
                                <div class="simo-evidence-event">
                                    <span class="text-xs font-medium text-indigo-600">{{ $r->keyword ?: 'Keyword no registrada' }}</span>
                                    <p class="text-sm font-semibold text-zinc-900">{{ $r->titulo ?: 'Sin título registrado' }}</p>
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-xs text-zinc-600">{{ $r->leido ? 'Leído' : 'Sin leer' }}</span>
                                        @if($r->relevante)<span class="simo-badge bg-emerald-50 text-emerald-700 text-[9px]">relevante</span>@endif
                                        @if($r->descartado)<span class="simo-badge bg-zinc-100 text-zinc-500 text-[9px]">descartado</span>@endif
                                        @if($r->archivado_at)<span class="simo-badge bg-sky-50 text-sky-700 text-[9px]">archivado</span>@endif
                                        @if(!$r->gemini_analyzed)
                                            <span class="simo-badge bg-zinc-100 text-zinc-600 text-[9px]">Pendiente</span>
                                        @elseif($r->gemini_is_pep)
                                            <x-simo-badge-categoria :categoria="$r->gemini_categoria" />
                                            @if($r->gemini_nombre)<span class="text-[10px] text-gray-600">{{ Str::limit($r->gemini_nombre, 30) }}</span>@endif
                                        @else
                                            <span class="simo-badge bg-zinc-100 text-zinc-600 text-[9px]">No relevante</span>
                                        @endif
                                        @if(($r->secondaries_count ?? 0) > 0)
                                            <span class="simo-badge bg-violet-50 text-violet-600 text-[9px]">+{{ $r->secondaries_count }} medios</span>
                                        @endif
                                    </div>
                                </div>
                                <details class="simo-evidence-row-sources" data-testid="table-sources" wire:key="table-sources-{{ $r->id }}">
                                    <summary>Ver u ocultar {{ $fuentesTabla[$r->id]->count() }} registro(s)</summary>
                                    <ul class="simo-evidence-source-list">
                                        @foreach($fuentesTabla[$r->id] as $fuente)
                                            <li wire:key="table-source-{{ $r->id }}-{{ $fuente->id }}">
                                                <strong>#{{ $fuente->id }} · {{ $fuente->sitio?->nombre ?? 'Sitio no registrado' }} · {{ $loop->first ? 'Primario registrado' : 'Fuente vinculada' }}</strong>
                                                <p>{{ $fuente->titulo ?: 'Sin título registrado' }}</p>
                                                <p class="text-zinc-600">{{ $fuente->keyword ?: 'Keyword no registrada' }} · {{ $fuente->fecha_encontrado?->format('d/m/Y H:i') ?? 'Fecha no registrada' }}</p>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if($fuentesTabla[$r->id]->count() === 1)<p class="text-xs text-zinc-600 mt-3">Sin fuentes vinculadas. No acredita cobertura exclusiva.</p>@endif
                                </details>
                            </td>
                            <td><p>{{ $r->sitio?->nombre ?? 'Sitio no registrado' }}</p><p class="text-zinc-600 mt-1">{{ $r->pais ?: 'País no registrado' }} · {{ $r->categoria ?: 'Categoría no registrada' }}</p></td>
                            <td><span class="font-semibold">{{ $r->relevance_score ?? '—' }}</span></td>
                            <td class="whitespace-nowrap">{{ $r->fecha_encontrado?->format('d/m/Y') ?? 'No registrada' }}<br><span class="text-zinc-600">{{ $r->fecha_encontrado?->format('H:i') }}</span></td>
                            <td>
                                <div class="simo-evidence-row-actions">
                                    <button type="button" wire:click="seleccionarResultado({{ $r->id }})" aria-expanded="{{ $seleccionado?->id === $r->id ? 'true' : 'false' }}"
                                        @if($seleccionado) aria-controls="evidence-panel" @endif
                                        class="simo-btn bg-indigo-50 text-indigo-600 hover:bg-indigo-100">Comparar fuentes</button>
                                    @include('livewire.scraper.partials.result-actions', ['r' => $r])
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-zinc-600">Sin resultados para los filtros aplicados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="px-5 py-3 border-t border-zinc-200">{{ $resultados->links() }}</footer>
    </section>

    @if($seleccionado)
        @include('livewire.scraper.partials.evidence-panel')
    @endif

    <details class="simo-evidence-help" data-testid="criteria-help">
        <summary>Criterios propuestos · no ejecutados</summary>
        <div class="space-y-3 mt-3 text-xs text-zinc-600 leading-relaxed">
            <p>Como criterios de revisión: misma persona inequívoca, institución, cargo, acción y fecha compatible. La similitud de títulos no basta. La ventana temporal está por definir.</p>
            <p>Identidad o fecha ausente requieren revisión; acciones o instituciones incompatibles no deben confundirse. Son criterios informativos de la maqueta: no se calculan recomendaciones ni se ejecutan uniones o separaciones.</p>
            <p>El agrupamiento actual y Gemini permanecen sin cambios. El primario no acredita autoría ni fuente original; la fecha de hallazgo no es la del evento.</p>
        </div>
    </details>

    {{-- Modal Personas detectadas --}}
    @if($verAnalisisId && $resultadoAnalisis)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
        wire:click.self="$set('verAnalisisId', null)">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
                <h2 class="font-semibold text-gray-800">Personas detectadas</h2>
                <button wire:click="$set('verAnalisisId', null)"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 text-lg">&times;</button>
            </div>
            <div class="px-6 py-4 overflow-y-auto">
                @if($resultadoAnalisis->gemini_motivo)
                    <div class="mb-4 bg-gray-50 rounded-xl px-4 py-3">
                        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Razonamiento Gemini</p>
                        <p class="text-xs text-gray-600 leading-relaxed">{{ $resultadoAnalisis->gemini_motivo }}</p>
                    </div>
                @endif
                @if($resultadoAnalisis->personas->isEmpty())
                    <p class="text-sm text-gray-500 text-center py-6">Sin personas detectadas</p>
                @else
                    <div class="space-y-3">
                        @foreach($resultadoAnalisis->personas as $persona)
                            <div class="{{ !$persona->threshold_passed ? 'opacity-60' : '' }} border border-gray-100 rounded-xl p-4 space-y-2">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-800">{{ $persona->nombre }}</p>
                                        @if($persona->cargo)
                                            <p class="text-xs text-gray-500">{{ $persona->cargo }}</p>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <x-simo-badge-categoria :categoria="$persona->categoria" />
                                        @if(!$persona->threshold_passed)
                                            <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200 text-[9px]">Baja confianza</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 text-xs text-gray-500">
                                    <span class="font-medium {{ $persona->getConfianzaColorClass() }}">
                                        {{ $persona->confianza }}% confianza
                                    </span>
                                    @if($persona->evento)
                                        <span>· {{ $persona->evento }}</span>
                                    @endif
                                </div>
                                @if($persona->motivo)
                                    <p class="text-xs text-gray-600 bg-gray-50 rounded-lg px-3 py-2">{{ $persona->motivo }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endif

</div>
