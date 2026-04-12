<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Familias de lemas</h1>
            <p class="text-sm text-gray-400 mt-0.5">Grupos de palabras morfológicamente relacionadas</p>
        </div>
        @can('gestionar familias lemas')
        <button wire:click="abrirModal()" class="simo-btn-primary text-sm px-4 py-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nueva familia
        </button>
        @endcan
    </div>

    {{-- Filtros --}}
    <div class="simo-card mb-4 flex gap-2 flex-wrap items-center">
        <div class="flex-1 min-w-[200px]">
            <input wire:model.live.debounce.400ms="busqueda" type="text"
                placeholder="Buscar raíz..."
                class="simo-input" />
        </div>
        <select wire:model.live="filtroCategoria" class="simo-select">
            <option value="">Todas las categorías</option>
            @foreach($this->categorias as $cat)
                <option value="{{ $cat->value }}">{{ ucfirst($cat->value) }}</option>
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
                    <th>Raíz</th>
                    <th>Variantes</th>
                    <th>Categoría</th>
                    <th>Estado</th>
                    @can('gestionar familias lemas')
                    <th>Acciones</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse($familias as $f)
                    <tr>
                        <td>
                            <div class="font-medium text-gray-800">{{ $f->raiz }}</div>
                        </td>
                        <td class="text-xs text-gray-500">
                            {{ implode(', ', array_slice($f->variantes, 0, 4)) }}
                            @if(count($f->variantes) > 4)
                                <span class="text-gray-400">+{{ count($f->variantes) - 4 }} más</span>
                            @endif
                        </td>
                        <td>
                            <span class="simo-badge
                                @if($f->categoria->value === 'designacion') bg-blue-50 text-blue-700
                                @elseif($f->categoria->value === 'renuncia') bg-amber-50 text-amber-700
                                @else bg-red-50 text-red-700 @endif
                            ">
                                {{ ucfirst($f->categoria->value) }}
                            </span>
                        </td>
                        <td>
                            <span class="simo-badge {{ $f->activo ? 'bg-green-50 text-green-700' : 'bg-zinc-100 text-zinc-500 border-zinc-200' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $f->activo ? 'bg-green-500' : 'bg-zinc-400' }}"></span>
                                {{ $f->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        @can('gestionar familias lemas')
                        <td>
                            <div class="flex items-center gap-1">
                                <button wire:click="abrirModal({{ $f->id }})"
                                        class="simo-btn-ghost text-xs">
                                    Editar
                                </button>
                                <button wire:click="toggleActivo({{ $f->id }})"
                                        class="simo-btn-ghost text-xs text-gray-400">
                                    {{ $f->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                                <button wire:click="eliminar({{ $f->id }})"
                                        wire:confirm="¿Eliminar esta familia?"
                                        class="simo-btn-ghost text-xs text-red-400">
                                    Eliminar
                                </button>
                            </div>
                        </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-12 text-center text-gray-400">
                            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            Sin familias registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-100">{{ $familias->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         wire:click.self="cerrarModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">{{ $editandoId ? 'Editar familia' : 'Nueva familia' }}</h2>
                <button wire:click="cerrarModal"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition text-lg leading-none">
                    &times;
                </button>
            </div>

            {{-- Modal body --}}
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Raíz *</label>
                    <input wire:model="raiz" type="text" class="simo-input @error('raiz') border-red-400 @enderror" />
                    @error('raiz') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Variantes * <span class="text-gray-400 font-normal">(una por línea)</span></label>
                    <textarea wire:model="variantesRaw" rows="5"
                        placeholder="designar&#10;designación&#10;designado&#10;designada"
                        class="simo-input font-mono text-xs @error('variantesRaw') border-red-400 @enderror"></textarea>
                    @error('variantesRaw') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Categoría *</label>
                        <select wire:model="categoria" class="simo-select w-full @error('categoria') border-red-400 @enderror">
                            <option value="">Seleccionar...</option>
                            @foreach($this->categorias as $cat)
                                <option value="{{ $cat->value }}">{{ ucfirst($cat->value) }}</option>
                            @endforeach
                        </select>
                        @error('categoria') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input wire:model="activo" type="checkbox"
                                class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <span class="text-gray-700">Activo</span>
                        </label>
                    </div>
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
