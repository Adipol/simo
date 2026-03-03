<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Sitios Web</h1>
        <button wire:click="abrirModal()"
                class="bg-blue-600 text-white text-sm px-3 py-1.5 rounded hover:bg-blue-700">
            Nuevo sitio
        </button>
    </div>

    {{-- Filtros --}}
    <div class="bg-white rounded-lg shadow p-3 mb-4 flex gap-2 flex-wrap">
        <input wire:model.live.debounce.400ms="busqueda" type="text" placeholder="Buscar nombre o URL..."
               class="border rounded px-2 py-1.5 text-sm w-full md:w-auto flex-1" />
        <select wire:model.live="filtroPais" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todos los paises</option>
            @foreach($paises as $p)
                <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
            @endforeach
        </select>
        <select wire:model.live="filtroActivo" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todos</option>
            <option value="1">Activos</option>
            <option value="0">Inactivos</option>
        </select>
    </div>

    {{-- Tabla --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nombre / URL</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pais</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Selectores</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($sitios as $s)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2">
                            <div class="font-medium text-gray-800">{{ $s->nombre }}</div>
                            <a href="{{ $s->url }}" target="_blank"
                               class="text-xs text-blue-500 hover:underline truncate block max-w-xs">{{ Str::limit($s->url, 70) }}</a>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600">{{ $s->pais }}</td>
                        <td class="px-3 py-2 text-xs text-gray-400">
                            @if($s->selector_links) <div>Links: <code>{{ $s->selector_links }}</code></div> @endif
                            @if($s->selector_article) <div>Article: <code>{{ $s->selector_article }}</code></div> @endif
                        </td>
                        <td class="px-3 py-2">
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $s->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $s->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1">
                                <button wire:click="abrirModal({{ $s->id }})"
                                        class="text-xs text-blue-600 hover:underline">Editar</button>
                                <button wire:click="toggleActivo({{ $s->id }})"
                                        class="text-xs text-gray-500 hover:underline">
                                    {{ $s->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">Sin sitios.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $sitios->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" wire:click.self="cerrarModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 p-6">
            <h2 class="font-semibold text-lg mb-4">{{ $editandoId ? 'Editar sitio' : 'Nuevo sitio' }}</h2>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-medium text-gray-600">URL *</label>
                    <input wire:model="url" type="url" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                    @error('url') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Nombre *</label>
                    <input wire:model="nombre" type="text" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                    @error('nombre') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-gray-600">Pais *</label>
                        <select wire:model="pais" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5">
                            @foreach($paises as $p)
                                <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end pb-0.5">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input wire:model="activo" type="checkbox" class="rounded" />
                            Activo
                        </label>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Selector CSS enlaces</label>
                    <input wire:model="selector_links" type="text" placeholder="ej: article a, .noticias a"
                           class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Selector CSS articulo</label>
                    <input wire:model="selector_article" type="text" placeholder="ej: article, .content"
                           class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-5">
                <button wire:click="cerrarModal" class="px-4 py-1.5 text-sm border rounded text-gray-600 hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="guardar" class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">
                    Guardar
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
