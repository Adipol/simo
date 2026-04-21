<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Sitios Web</h1>
            <p class="text-sm text-gray-400 mt-0.5">Fuentes monitoreadas por el scraper</p>
        </div>
        @can('gestionar sitios')
        <button wire:click="abrirModal()" class="simo-btn-primary text-sm px-4 py-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nuevo sitio
        </button>
        @endcan
    </div>

    {{-- Filtros --}}
    <div class="simo-card mb-4 flex gap-2 flex-wrap items-center">
        <div class="flex-1 min-w-[200px]">
            <input wire:model.live.debounce.400ms="busqueda" type="text"
                placeholder="Buscar nombre o URL..."
                class="simo-input" />
        </div>
        <select wire:model.live="filtroPais" class="simo-select">
            <option value="">Todos los paises</option>
            @foreach($paises as $p)
                <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
            @endforeach
        </select>
        <select wire:model.live="filtroActivo" class="simo-select">
            <option value="">Todos</option>
            <option value="1">Activos</option>
            <option value="0">Inactivos</option>
        </select>
    </div>

    {{-- Tabla --}}
    <div class="simo-card p-0 overflow-hidden">
        <table class="simo-table min-w-full">
            <thead>
                <tr>
                    <th>Nombre / URL</th>
                    <th>Pais</th>
                    <th>Selectores CSS</th>
                    <th>Estado</th>
                    @can('gestionar sitios')
                    <th>Acciones</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse($sitios as $s)
                    <tr wire:key="{{ $s->id }}">
                        <td>
                            <div class="font-medium text-gray-800">{{ $s->nombre }}</div>
                            <a href="{{ $s->url }}" target="_blank"
                               class="text-xs text-indigo-500 hover:underline block max-w-sm truncate"
                               rel="noopener noreferrer">
                                {{ Str::limit($s->url, 60) }}
                            </a>
                        </td>
                        <td>
                            <span class="simo-badge bg-gray-100 text-gray-600">{{ $s->pais }}</span>
                        </td>
                        <td class="text-xs text-gray-400 font-mono">
                            @if($s->selector_links)
                                <div class="truncate max-w-[160px]" title="{{ $s->selector_links }}">
                                    <span class="text-gray-500">links:</span> {{ $s->selector_links }}
                                </div>
                            @endif
                            @if($s->selector_article)
                                <div class="truncate max-w-[160px]" title="{{ $s->selector_article }}">
                                    <span class="text-gray-500">article:</span> {{ $s->selector_article }}
                                </div>
                            @endif
                            @if(!$s->selector_links && !$s->selector_article)
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="simo-badge {{ $s->activo ? 'bg-green-50 text-green-700' : 'bg-zinc-100 text-zinc-500 border-zinc-200' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $s->activo ? 'bg-green-500' : 'bg-zinc-400' }}"></span>
                                {{ $s->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        @can('gestionar sitios')
                        <td>
                            <div class="flex items-center gap-1">
                                <button wire:click="abrirModal({{ $s->id }})"
                                        class="simo-btn-ghost text-xs">
                                    Editar
                                </button>
                                <button wire:click="toggleActivo({{ $s->id }})"
                                        class="simo-btn-ghost text-xs text-gray-400">
                                    {{ $s->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </div>
                        </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ auth()->user()?->can('gestionar sitios') ? 5 : 4 }}" class="py-12 text-center text-gray-400">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3"/>
                            </svg>
                            Sin sitios registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-100">{{ $sitios->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         wire:click.self="cerrarModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">{{ $editandoId ? 'Editar sitio' : 'Nuevo sitio' }}</h2>
                <button wire:click="cerrarModal"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition text-lg leading-none">
                    &times;
                </button>
            </div>

            {{-- Modal body --}}
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">URL *</label>
                    <input wire:model="url" type="url" class="simo-input @error('url') border-red-400 @enderror" />
                    @error('url') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Nombre *</label>
                    <input wire:model="nombre" type="text" class="simo-input @error('nombre') border-red-400 @enderror" />
                    @error('nombre') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Pais *</label>
                        <select wire:model="pais" class="simo-select w-full @error('pais') border-red-400 @enderror">
                            <option value="">Seleccione un país</option>
                            @foreach($paises as $p)
                                <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
                            @endforeach
                        </select>
                        @error('pais') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input wire:model="activo" type="checkbox"
                                class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <span class="text-gray-700">Activo</span>
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Selector CSS enlaces</label>
                    <input wire:model="selector_links" type="text"
                        placeholder="ej: article a, .noticias a"
                        class="simo-input font-mono" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Selector CSS articulo</label>
                    <input wire:model="selector_article" type="text"
                        placeholder="ej: article, .content"
                        class="simo-input font-mono" />
                </div>
            </div>

            {{-- Modal footer --}}
            <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-100 bg-gray-50/50 rounded-b-2xl">
                <button wire:click="cerrarModal" class="simo-btn-ghost text-sm px-4 py-2 border border-gray-200">
                    Cancelar
                </button>
                <button wire:click="guardar" class="simo-btn-primary text-sm px-4 py-2">
                    Guardar
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
