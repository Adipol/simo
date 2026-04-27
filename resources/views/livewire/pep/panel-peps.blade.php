<div class="space-y-4">

    {{-- ── Filtros ─────────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-2 flex-wrap">

        <select wire:model.live="filtroCategoria" class="simo-select">
            <option value="">Todas las categorías</option>
            <option value="PEP">PEP</option>
            <option value="OPI">OPI</option>
        </select>

        <input wire:model.live.debounce.500ms="fechaDesde" type="date"
            class="simo-input"
            title="Fecha desde" />

        <input wire:model.live.debounce.500ms="fechaHasta" type="date"
            class="simo-input"
            title="Fecha hasta" />

        <label class="flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer select-none">
            <input wire:model.live="mostrarSinClasificar" type="checkbox"
                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
            Mostrar sin clasificar
        </label>

        {{-- Limpiar filtros --}}
        @if($filtroCategoria !== '' || $fechaDesde !== '' || $fechaHasta !== '' || $mostrarSinClasificar)
            <button
                wire:click="$set('filtroCategoria', ''); $set('fechaDesde', ''); $set('fechaHasta', ''); $set('mostrarSinClasificar', false)"
                class="simo-btn-ghost text-gray-400 text-xs">
                Limpiar filtros
            </button>
        @endif

    </div>

    {{-- ── Loading skeleton ────────────────────────────────────────────────── --}}
    <div wire:loading.delay class="space-y-3">
        @for ($i = 0; $i < 3; $i++)
            <div class="simo-card animate-pulse">
                <div class="flex items-start gap-3 p-4">
                    <div class="flex-1 space-y-2">
                        <div class="h-4 bg-gray-200 rounded w-1/3"></div>
                        <div class="h-3 bg-gray-100 rounded w-1/2"></div>
                        <div class="h-3 bg-gray-100 rounded w-1/4"></div>
                    </div>
                </div>
            </div>
        @endfor
    </div>

    {{-- ── Cards ───────────────────────────────────────────────────────────── --}}
    <div wire:loading.remove>
        @forelse($this->eventos as $evento)
            <div wire:key="{{ $evento->key() }}"
                 class="simo-card mb-3 {{ $evento->isArchived ? 'opacity-60' : '' }}">

                {{-- Card header --}}
                <div class="flex items-start justify-between gap-3 p-4 pb-2">
                    <div class="flex-1 min-w-0">

                        {{-- Nombre + badges --}}
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold text-gray-800 truncate">
                                {{ $evento->nombreNormalizado }}
                            </span>

                            {{-- Categoria badge --}}
                            <span class="simo-badge {{ $evento->categoria === 'PEP' ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600' }}"
                                  style="font-size:9px">
                                {{ $evento->categoria }}
                            </span>

                            {{-- Evento badge --}}
                            @if($evento->evento === null)
                                <span class="simo-badge bg-gray-100 text-gray-400 border-gray-200"
                                      style="font-size:9px">
                                    Sin clasificar
                                </span>
                            @elseif($evento->evento === 'renuncia')
                                <span class="simo-badge bg-red-50 text-red-600 border-red-100"
                                      style="font-size:9px">
                                    renuncia
                                </span>
                            @elseif($evento->evento === 'designacion')
                                <span class="simo-badge bg-green-50 text-green-600 border-green-100"
                                      style="font-size:9px">
                                    designación
                                </span>
                            @elseif($evento->evento === 'crimen')
                                <span class="simo-badge bg-amber-50 text-amber-700 border-amber-100"
                                      style="font-size:9px">
                                    crimen
                                </span>
                            @else
                                <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200"
                                      style="font-size:9px">
                                    {{ $evento->evento }}
                                </span>
                            @endif

                            {{-- Archivado badge --}}
                            @if($evento->isArchived)
                                <span class="simo-badge bg-sky-50 text-sky-600 border-sky-100"
                                      style="font-size:9px">
                                    archivado
                                </span>
                            @endif
                        </div>

                        {{-- Cargo --}}
                        @if($evento->cargo)
                            <p class="text-xs text-gray-500 mt-0.5 truncate">
                                {{ $evento->cargo }}
                            </p>
                        @endif

                    </div>

                    {{-- Fecha --}}
                    <div class="text-xs text-gray-400 whitespace-nowrap shrink-0">
                        📅 {{ $evento->dia->format('d/m/Y') }}
                    </div>
                </div>

                {{-- Fuentes expandibles (Alpine) --}}
                <div class="px-4 pb-2"
                     x-data="{ open: false }">
                    <button @click="open = !open"
                        class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-indigo-600 transition-colors">
                        <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                        📰 {{ $evento->numFuentes }} {{ $evento->numFuentes === 1 ? 'fuente' : 'fuentes' }}
                    </button>

                    <ul x-show="open" x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="mt-1.5 space-y-0.5 pl-5"
                        style="display:none">
                        @foreach($evento->sitios as $sitio)
                            <li class="text-xs text-gray-500">
                                — Sitio #{{ $sitio }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Acciones --}}
                <div class="flex items-center gap-2 px-4 py-2 border-t border-gray-50">
                    <button
                        wire:click="verArticulos({{ json_encode($evento->nombreNormalizado) }})"
                        class="simo-btn bg-indigo-50 text-indigo-600 hover:bg-indigo-100 text-xs">
                        Ver artículos →
                    </button>

                    @if(! $evento->isArchived)
                        <button
                            wire:click="archivar({{ json_encode($evento->resultadoIds) }})"
                            wire:confirm="¿Archivar {{ $evento->numFuentes }} artículo(s) de este grupo?"
                            class="simo-btn bg-gray-50 text-gray-500 hover:bg-sky-50 hover:text-sky-600 text-xs">
                            Archivar grupo
                        </button>
                    @endif
                </div>

            </div>

        @empty
            {{-- Empty state --}}
            @if($filtroCategoria !== '' || $fechaDesde !== '' || $fechaHasta !== '' || $mostrarSinClasificar)
                {{-- Filters active but no results --}}
                <div class="simo-card p-10 text-center">
                    <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                    <p class="text-sm text-gray-400 mb-3">Ningún evento coincide con los filtros.</p>
                    <button
                        wire:click="$set('filtroCategoria', ''); $set('fechaDesde', ''); $set('fechaHasta', ''); $set('mostrarSinClasificar', false)"
                        class="simo-btn bg-gray-100 text-gray-600 text-xs">
                        Limpiar filtros
                    </button>
                </div>
            @else
                {{-- No data at all --}}
                <div class="simo-card p-10 text-center">
                    <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                    </svg>
                    <p class="text-sm font-medium text-gray-500 mb-1">No hay eventos PEP detectados aún.</p>
                    <p class="text-xs text-gray-400">Esperá al próximo ciclo de scraping para ver nuevos resultados.</p>
                </div>
            @endif
        @endforelse
    </div>

    {{-- ── Paginación ───────────────────────────────────────────────────────── --}}
    @if($this->eventos->hasPages())
        <div class="pt-1">
            {{ $this->eventos->links() }}
        </div>
    @endif

</div>
