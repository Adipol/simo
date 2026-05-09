<div class="space-y-4">

    {{-- Toolbar --}}
    <div class="flex items-center gap-2 flex-wrap">
        <input wire:model.live.debounce.400ms="busqueda" type="text"
            placeholder="Buscar nombre, URL u organismo..."
            class="simo-input w-64" />
        <select wire:model.live="filtroNivel" class="simo-select">
            <option value="">Todos los niveles</option>
            <option value="nacional">Nacional</option>
            <option value="regional">Regional</option>
            <option value="municipal">Municipal</option>
            <option value="judicial">Judicial</option>
            <option value="legislativo">Legislativo</option>
            <option value="otro">Otro</option>
        </select>
        <select wire:model.live="filtroPais" class="simo-select">
            <option value="">Todos los países</option>
            @foreach($paises as $p)
                <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
            @endforeach
        </select>
        <select wire:model.live="filtroActivo" class="simo-select">
            <option value="">Todas</option>
            <option value="1">Activas</option>
            <option value="0">Inactivas</option>
        </select>
        <div class="ml-auto">
            <button wire:click="abrirModal()" class="simo-btn-primary">
                + Nueva fuente
            </button>
        </div>
    </div>

    {{-- Tabla --}}
    <div class="simo-card p-0 overflow-hidden">
        <table class="simo-table min-w-full">
            <thead>
                <tr>
                    <th>Fuente</th>
                    <th>País / Nivel</th>
                    <th>Tipo</th>
                    <th>Último check</th>
                    <th class="text-center">Cambios</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($fuentes as $f)
                    <tr wire:key="fuente-{{ $f->id }}">
                        <td>
                            <div class="text-xs font-semibold text-gray-800">{{ $f->nombre ?? $f->organismo ?? '—' }}</div>
                            @if($f->organismo && $f->nombre)
                                <div class="text-[10px] text-gray-400 mt-0.5">{{ $f->organismo }}</div>
                            @endif
                            <a href="{{ $f->url }}" target="_blank"
                               class="text-[10px] text-indigo-500 hover:text-indigo-700 truncate block max-w-xs mt-0.5">
                                {{ Str::limit($f->url, 55) }}
                            </a>
                        </td>
                        <td>
                            <div class="text-xs font-medium text-gray-700">
                                {{ $f->paisRelacion?->nombre ?? ($f->pais ? strtoupper($f->pais) : '—') }}
                            </div>
                            <div class="text-[10px] capitalize text-gray-400 mt-0.5">{{ $f->nivel }}</div>
                        </td>
                        <td>
                            <div class="flex flex-col gap-1">
                                <span class="text-[10px] uppercase font-medium text-gray-500 tracking-wider">{{ $f->tipo }}</span>
                                @if($f->analizar_imagenes)
                                    <span class="simo-badge bg-amber-50 text-amber-700 border-amber-200 inline-flex items-center gap-1 w-fit"
                                          title="Análisis multimodal de imágenes activo">
                                        📷 Multimodal
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="text-xs text-gray-500">
                            {{ $f->ultimo_check ? $f->ultimo_check->diffForHumans() : 'Nunca' }}
                        </td>
                        <td class="text-center">
                            @if($f->cambios_sin_revisar > 0)
                                <a href="{{ route('pep.cambios') }}"
                                   class="simo-badge bg-amber-50 text-amber-600 hover:bg-amber-100 transition-colors">
                                    {{ $f->cambios_sin_revisar }} pendiente{{ $f->cambios_sin_revisar > 1 ? 's' : '' }}
                                </a>
                            @else
                                <span class="text-xs text-gray-300">0</span>
                            @endif
                        </td>
                        <td>
                            <span class="simo-badge {{ $f->activo ? 'bg-emerald-50 text-emerald-600' : 'bg-zinc-100 text-zinc-500 border-zinc-200' }}">
                                {{ $f->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <button wire:click="abrirModal({{ $f->id }})"
                                    class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Editar</button>
                                <button wire:click="toggleActivo({{ $f->id }})"
                                    class="text-xs text-gray-400 hover:text-gray-600">
                                    {{ $f->activo ? 'Desactivar' : 'Activar' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-12 text-center text-sm text-gray-400">Sin fuentes registradas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-gray-50">{{ $fuentes->links() }}</div>
    </div>

    {{-- Modal --}}
    @if($modalAbierto)
    <div class="fixed inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         wire:click.self="cerrarModal">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">{{ $editandoId ? 'Editar fuente' : 'Nueva fuente' }}</h2>
                <button wire:click="cerrarModal" class="text-gray-400 hover:text-gray-600 transition">&times;</button>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">URL *</label>
                    <input wire:model="url" type="url" class="simo-input" />
                    @error('url') <p class="text-xs text-rose-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
                        <input wire:model="nombre" type="text" class="simo-input" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Organismo</label>
                        <input wire:model="organismo" type="text" class="simo-input" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">País</label>
                        <select wire:model="pais" class="simo-input">
                            <option value="">— Sin país —</option>
                            @foreach($paises as $p)
                                <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
                            @endforeach
                        </select>
                        @error('pais') <p class="text-xs text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nivel</label>
                        <select wire:model="nivel" class="simo-input">
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
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tipo</label>
                        <select wire:model="tipo" class="simo-input">
                            <option value="html">HTML</option>
                            <option value="pdf">PDF</option>
                            <option value="js">JS (Playwright)</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
                            <input wire:model="activo" type="checkbox" class="rounded border-gray-300 text-indigo-600" />
                            <span class="text-gray-700">Activa</span>
                        </label>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Selector CSS (opcional)</label>
                    <input wire:model="selector_css" type="text"
                        placeholder="ej: table.funcionarios, #lista-peps"
                        class="simo-input" />
                </div>

                <div class="rounded-lg border border-amber-200 bg-amber-50/60 px-4 py-3">
                    <label class="flex items-start gap-3 cursor-pointer select-none">
                        <input wire:model="analizar_imagenes" type="checkbox"
                               class="mt-0.5 rounded border-amber-300 text-amber-600 focus:ring-amber-400" />
                        <span class="text-sm">
                            <span class="font-medium text-amber-900">Analizar imágenes con Gemini multimodal</span>
                            <span class="block text-[11px] text-amber-700 mt-0.5 leading-snug">
                                Activar SOLO si los nombres de PEPs aparecen <strong>dentro</strong> de imágenes
                                (nómina escaneada, organigrama en PNG). Si los nombres están en texto del HTML
                                (lo más común), dejá desactivado para evitar consumo innecesario de tokens y
                                falsos positivos.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
            <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-100">
                <button wire:click="cerrarModal" class="simo-btn bg-gray-100 text-gray-600 hover:bg-gray-200">
                    Cancelar
                </button>
                <button wire:click="guardar" class="simo-btn-primary">
                    Guardar
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
