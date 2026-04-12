<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Palabras Clave</h1>
            <p class="text-sm text-gray-400 mt-0.5">Keywords para filtrar resultados del scraper</p>
        </div>
        @can('gestionar keywords')
        <button wire:click="abrirModal()" class="simo-btn-primary text-sm px-4 py-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nueva keyword
        </button>
        @endcan
    </div>

    {{-- Filtros --}}
    <div class="simo-card mb-4 flex gap-2 flex-wrap items-center">
        <div class="flex-1 min-w-[200px]">
            <input wire:model.live.debounce.400ms="busqueda" type="text"
                placeholder="Buscar keyword o categoria..."
                class="simo-input" />
        </div>
        <select wire:model.live="filtroActivo" class="simo-select">
            <option value="">Todas</option>
            <option value="1">Activas</option>
            <option value="0">Inactivas</option>
        </select>
    </div>

    {{-- Tabla --}}
    <div class="simo-card p-0 overflow-hidden">
        <table class="simo-table min-w-full">
            <thead>
                <tr>
                    <th>Keyword</th>
                    <th>Categoria</th>
                    <th>Estado</th>
                    @can('gestionar keywords')
                    <th>Acciones</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse($keywords as $k)
                    <tr wire:key="keyword-{{ $k->id }}">
                        <td class="font-medium text-gray-800">{{ $k->keyword }}</td>
                        <td>
                            @if($k->categoria)
                                <span class="simo-badge bg-indigo-50 text-indigo-600">{{ $k->categoria }}</span>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="simo-badge {{ $k->activo ? 'bg-green-50 text-green-700' : 'bg-zinc-100 text-zinc-500 border-zinc-200' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $k->activo ? 'bg-green-500' : 'bg-zinc-400' }}"></span>
                                {{ $k->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        @can('gestionar keywords')
                        <td>
                            <div class="flex items-center gap-1">
                                <button wire:click="abrirModal({{ $k->id }})"
                                        class="simo-btn-ghost text-xs">
                                    Editar
                                </button>
                                <button wire:click="toggleActivo({{ $k->id }})"
                                        class="simo-btn-ghost text-xs text-gray-400">
                                    {{ $k->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </div>
                        </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-12 text-center text-gray-400">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            Sin keywords registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-100">{{ $keywords->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         wire:click.self="cerrarModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md">
            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">{{ $editandoId ? 'Editar keyword' : 'Nueva keyword' }}</h2>
                <button wire:click="cerrarModal"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition text-lg leading-none">
                    &times;
                </button>
            </div>

            {{-- Modal body --}}
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Keyword *</label>
                    <input wire:model="keyword" type="text"
                        class="simo-input @error('keyword') border-red-400 @enderror" />
                    @error('keyword') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Categoria <span class="text-gray-400">(opcional)</span></label>
                    <input wire:model="categoria" type="text" placeholder="ej: politica, economia"
                        class="simo-input" />
                </div>
                <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                    <input wire:model="activo" type="checkbox"
                        class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <span class="text-gray-700">Activa</span>
                </label>
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
