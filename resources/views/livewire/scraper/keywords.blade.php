<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Palabras Clave</h1>
        <button wire:click="abrirModal()"
                class="bg-blue-600 text-white text-sm px-3 py-1.5 rounded hover:bg-blue-700">
            Nueva keyword
        </button>
    </div>

    {{-- Filtros --}}
    <div class="bg-white rounded-lg shadow p-3 mb-4 flex gap-2 flex-wrap">
        <input wire:model.live.debounce.400ms="busqueda" type="text" placeholder="Buscar keyword o categoria..."
               class="border rounded px-2 py-1.5 text-sm flex-1" />
        <select wire:model.live="filtroActivo" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todas</option>
            <option value="1">Activas</option>
            <option value="0">Inactivas</option>
        </select>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Keyword</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Categoria</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($keywords as $k)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-medium text-gray-800">{{ $k->keyword }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $k->categoria ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $k->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $k->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1">
                                <button wire:click="abrirModal({{ $k->id }})"
                                        class="text-xs text-blue-600 hover:underline">Editar</button>
                                <button wire:click="toggleActivo({{ $k->id }})"
                                        class="text-xs text-gray-500 hover:underline">
                                    {{ $k->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-400">Sin keywords.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $keywords->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" wire:click.self="cerrarModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
            <h2 class="font-semibold text-lg mb-4">{{ $editandoId ? 'Editar keyword' : 'Nueva keyword' }}</h2>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-medium text-gray-600">Keyword *</label>
                    <input wire:model="keyword" type="text" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                    @error('keyword') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Categoria</label>
                    <input wire:model="categoria" type="text" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                </div>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input wire:model="activo" type="checkbox" class="rounded" />
                    Activa
                </label>
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
