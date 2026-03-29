<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Países</h1>
            <p class="text-sm text-gray-400 mt-0.5">Gestiona los países disponibles en el sistema</p>
        </div>
        @can('gestionar sitios')
        <button wire:click="abrirCrear" class="simo-btn-primary text-sm px-4 py-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nuevo país
        </button>
        @endcan
    </div>

    {{-- Filtros --}}
    <div class="simo-card mb-4 flex gap-2 items-center">
        <div class="flex-1">
            <input wire:model.live.debounce.300ms="busqueda" type="text"
                placeholder="Buscar por nombre o código..."
                class="simo-input" />
        </div>
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
                    <th class="w-24">Código</th>
                    <th>Nombre</th>
                    <th>Sitios vinculados</th>
                    <th>Estado</th>
                    @can('gestionar sitios')
                    <th>Acciones</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse($paises as $pais)
                    <tr>
                        <td>
                            <span class="font-mono font-semibold text-gray-800 text-sm bg-gray-100 px-2 py-0.5 rounded-md">
                                {{ $pais->codigo }}
                            </span>
                        </td>
                        <td class="font-medium text-gray-800">{{ $pais->nombre }}</td>
                        <td>
                            @if($pais->sitios_web_count > 0 || $pais->fuentes_count > 0)
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    @if($pais->sitios_web_count > 0)
                                        <span class="simo-badge bg-indigo-50 text-indigo-600">
                                            {{ $pais->sitios_web_count }} {{ $pais->sitios_web_count === 1 ? 'sitio' : 'sitios' }}
                                        </span>
                                    @endif
                                    @if($pais->fuentes_count > 0)
                                        <span class="simo-badge bg-purple-50 text-purple-600">
                                            {{ $pais->fuentes_count }} {{ $pais->fuentes_count === 1 ? 'fuente' : 'fuentes' }}
                                        </span>
                                    @endif
                                </div>
                            @else
                                <span class="text-gray-300 text-xs">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="simo-badge {{ $pais->activo ? 'bg-green-50 text-green-700' : 'bg-zinc-100 text-zinc-500 border-zinc-200' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $pais->activo ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                {{ $pais->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        @can('gestionar sitios')
                        <td>
                            <div class="flex items-center gap-1">
                                <button wire:click="abrirEditar('{{ $pais->codigo }}')"
                                    class="simo-btn-ghost text-xs">
                                    Editar
                                </button>
                                <button wire:click="toggleActivo('{{ $pais->codigo }}')"
                                    class="simo-btn-ghost text-xs text-gray-400">
                                    {{ $pais->activo ? 'Desactivar' : 'Activar' }}
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
                                    d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/>
                            </svg>
                            No hay países registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-100">{{ $paises->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         wire:click.self="cerrarModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm">

            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">{{ $esEdicion ? 'Editar país' : 'Nuevo país' }}</h2>
                <button wire:click="cerrarModal"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 transition text-lg leading-none">
                    &times;
                </button>
            </div>

            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wider mb-1.5">
                        Código ISO *
                    </label>
                    <input wire:model="codigo" type="text"
                        maxlength="2"
                        placeholder="ej: PE, AR, CL"
                        @if($esEdicion) readonly @endif
                        class="simo-input uppercase font-mono tracking-widest text-center text-lg
                               @if($esEdicion) bg-gray-50 text-gray-500 cursor-not-allowed @endif
                               @error('codigo') border-red-400 @enderror" />
                    @error('codigo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    @if(!$esEdicion)
                        <p class="text-xs text-gray-400 mt-1">2 letras según estándar ISO 3166-1 alpha-2</p>
                    @endif
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-500 uppercase tracking-wider mb-1.5">
                        Nombre *
                    </label>
                    <input wire:model="nombre" type="text"
                        placeholder="ej: Perú, Argentina, Chile"
                        class="simo-input @error('nombre') border-red-400 @enderror" />
                    @error('nombre') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2.5 cursor-pointer select-none">
                    <input wire:model="activo" type="checkbox"
                        class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">País activo</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-100 bg-gray-50/50 rounded-b-2xl">
                <button wire:click="cerrarModal" class="simo-btn-ghost text-sm px-4 py-2 border border-gray-200">
                    Cancelar
                </button>
                <button wire:click="guardar" class="simo-btn-primary text-sm px-4 py-2">
                    {{ $esEdicion ? 'Guardar' : 'Agregar país' }}
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
