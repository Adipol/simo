<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Entidades Públicas</h1>
            <p class="text-sm text-gray-400 mt-0.5">Entidades del Estado conocidas por país</p>
        </div>
        @can('gestionar entidades publicas')
        <button wire:click="abrirModal()" class="simo-btn-primary text-sm px-4 py-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nueva entidad
        </button>
        @endcan
    </div>

    {{-- Filtros --}}
    <div class="simo-card mb-4 flex gap-2 flex-wrap items-center">
        <div class="flex-1 min-w-[200px]">
            <input wire:model.live.debounce.400ms="busqueda" type="text"
                placeholder="Buscar nombre o sigla..."
                class="simo-input" />
        </div>
        <select wire:model.live="filtroPais" class="simo-select">
            <option value="">Todos los países</option>
            @foreach($this->paises as $pais)
                <option value="{{ $pais->codigo }}">{{ $pais->nombre }}</option>
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
                    <th>Nombre</th>
                    <th>Sigla</th>
                    <th>País</th>
                    <th>Estado</th>
                    @can('gestionar entidades publicas')
                    <th>Acciones</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse($entidades as $entidad)
                    <tr>
                        <td>
                            <div class="font-medium text-gray-800">{{ $entidad->nombre }}</div>
                        </td>
                        <td class="text-sm text-gray-500">
                            {{ $entidad->sigla ?? '—' }}
                        </td>
                        <td class="text-sm text-gray-600">
                            {{ $entidad->pais?->nombre ?? $entidad->pais_codigo }}
                        </td>
                        <td>
                            <span class="simo-badge {{ $entidad->activo ? 'bg-green-50 text-green-700' : 'bg-zinc-100 text-zinc-500 border-zinc-200' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $entidad->activo ? 'bg-green-500' : 'bg-zinc-400' }}"></span>
                                {{ $entidad->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        @can('gestionar entidades publicas')
                        <td>
                            <div class="flex items-center gap-1">
                                <button wire:click="abrirModal({{ $entidad->id }})"
                                        class="simo-btn-ghost text-xs">
                                    Editar
                                </button>
                                <button wire:click="toggleActivo({{ $entidad->id }})"
                                        class="simo-btn-ghost text-xs text-gray-400">
                                    {{ $entidad->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                                <button wire:click="eliminar({{ $entidad->id }})"
                                        wire:confirm="¿Eliminar esta entidad?"
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
                                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                            Sin entidades registradas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-100">{{ $entidades->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         wire:click.self="cerrarModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg">
            {{-- Modal header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">{{ $editandoId ? 'Editar entidad' : 'Nueva entidad' }}</h2>
                <button wire:click="cerrarModal"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition text-lg leading-none">
                    &times;
                </button>
            </div>

            {{-- Modal body --}}
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Nombre *</label>
                    <input wire:model="nombre" type="text" class="simo-input @error('nombre') border-red-400 @enderror" />
                    @error('nombre') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Sigla <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input wire:model="sigla" type="text" class="simo-input @error('sigla') border-red-400 @enderror"
                           placeholder="Ej: ME, BCR, ANSES" />
                    @error('sigla') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">País *</label>
                        <select wire:model="paisCodigo" class="simo-select w-full @error('paisCodigo') border-red-400 @enderror">
                            <option value="">— Seleccionar país —</option>
                            @foreach($this->paises as $pais)
                                <option value="{{ $pais->codigo }}">{{ $pais->nombre }}</option>
                            @endforeach
                        </select>
                        @error('paisCodigo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
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
