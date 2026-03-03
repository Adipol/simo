<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Fuentes PEP</h1>
        <div class="flex gap-2 items-center">
            <a href="{{ route('pep.cambios') }}" class="text-sm text-blue-600 hover:underline">Ver cambios</a>
            <button wire:click="abrirModal()"
                    class="bg-blue-600 text-white text-sm px-3 py-1.5 rounded hover:bg-blue-700">
                Nueva fuente
            </button>
        </div>
    </div>

    {{-- Filtros --}}
    <div class="bg-white rounded-lg shadow p-3 mb-4 flex gap-2 flex-wrap">
        <input wire:model.live.debounce.400ms="busqueda" type="text" placeholder="Buscar nombre, URL u organismo..."
               class="border rounded px-2 py-1.5 text-sm flex-1" />
        <select wire:model.live="filtroNivel" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todos los niveles</option>
            <option value="nacional">Nacional</option>
            <option value="regional">Regional</option>
            <option value="municipal">Municipal</option>
            <option value="judicial">Judicial</option>
            <option value="legislativo">Legislativo</option>
            <option value="otro">Otro</option>
        </select>
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
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fuente</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nivel / Tipo</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ultimo check</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cambios</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($fuentes as $f)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 max-w-xs">
                            <div class="font-medium text-gray-800 text-xs">{{ $f->nombre ?? $f->organismo ?? '—' }}</div>
                            @if($f->organismo && $f->nombre) <div class="text-xs text-gray-500">{{ $f->organismo }}</div> @endif
                            <a href="{{ $f->url }}" target="_blank"
                               class="text-xs text-blue-500 hover:underline truncate block">{{ Str::limit($f->url, 60) }}</a>
                            @if($f->pais) <div class="text-xs text-gray-400">{{ $f->pais }}</div> @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600">
                            <div class="capitalize">{{ $f->nivel }}</div>
                            <div class="uppercase text-gray-400">{{ $f->tipo }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-500">
                            {{ $f->ultimo_check ? $f->ultimo_check->diffForHumans() : 'Nunca' }}
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if($f->cambios_sin_revisar > 0)
                                <a href="{{ route('pep.cambios') }}"
                                   class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium">
                                    {{ $f->cambios_sin_revisar }} pendiente{{ $f->cambios_sin_revisar > 1 ? 's' : '' }}
                                </a>
                            @else
                                <span class="text-xs text-gray-300">0</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $f->activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $f->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1">
                                <button wire:click="abrirModal({{ $f->id }})"
                                        class="text-xs text-blue-600 hover:underline">Editar</button>
                                <button wire:click="toggleActivo({{ $f->id }})"
                                        class="text-xs text-gray-500 hover:underline">
                                    {{ $f->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-400">Sin fuentes.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $fuentes->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" wire:click.self="cerrarModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-xl mx-4 p-6 max-h-screen overflow-y-auto">
            <h2 class="font-semibold text-lg mb-4">{{ $editandoId ? 'Editar fuente' : 'Nueva fuente' }}</h2>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-medium text-gray-600">URL *</label>
                    <input wire:model="url" type="url" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                    @error('url') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-gray-600">Nombre</label>
                        <input wire:model="nombre" type="text" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-600">Organismo</label>
                        <input wire:model="organismo" type="text" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-gray-600">Pais</label>
                        <input wire:model="pais" type="text" placeholder="ej: Bolivia" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-600">Nivel</label>
                        <select wire:model="nivel" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5">
                            <option value="nacional">Nacional</option>
                            <option value="regional">Regional</option>
                            <option value="municipal">Municipal</option>
                            <option value="judicial">Judicial</option>
                            <option value="legislativo">Legislativo</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-gray-600">Tipo</label>
                        <select wire:model="tipo" class="w-full border rounded px-2 py-1.5 text-sm mt-0.5">
                            <option value="html">HTML</option>
                            <option value="pdf">PDF</option>
                            <option value="js">JS (Playwright)</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-0.5">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input wire:model="activo" type="checkbox" class="rounded" />
                            Activa
                        </label>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Selector CSS (opcional)</label>
                    <input wire:model="selector_css" type="text" placeholder="ej: table.funcionarios, #lista-peps"
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
