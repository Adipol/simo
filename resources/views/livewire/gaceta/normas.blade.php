<div class="space-y-4">

    {{-- ── Filtros ────────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-2 flex-wrap">
        <select wire:model.live="pais" class="simo-select">
            <option value="">Todos los países</option>
            <option value="BO">Bolivia</option>
            <option value="HN">Honduras</option>
            <option value="SV">El Salvador</option>
            <option value="NI">Nicaragua</option>
            <option value="PY">Paraguay</option>
            <option value="GT">Guatemala</option>
        </select>
        <select wire:model.live="tipo" class="simo-select">
            <option value="">Todos los tipos</option>
            <option value="requiere_revision">Revisar</option>
            <option value="requiere_detalle">Bulk / Detalle</option>
        </select>
    </div>

    {{-- ── Empty state ─────────────────────────────────────────────────── --}}
    @if($normas->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <svg class="w-12 h-12 text-zinc-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm font-medium text-zinc-500">No hay normas pendientes de revisión.</p>
            @if($pais !== '' || $tipo !== '')
                <button wire:click="$set('pais', '')" class="mt-2 text-xs text-indigo-600 hover:underline">
                    Ver todos los países
                </button>
            @endif
        </div>
    @else

    {{-- ── Lista de normas ─────────────────────────────────────────────── --}}
    <div class="space-y-3">
        @foreach($normas as $norma)
        <div wire:key="norma-{{ $norma->id }}" class="rounded-xl border border-zinc-200 bg-white overflow-hidden">

            {{-- Card header: meta + actions --}}
            <div class="flex items-start justify-between gap-4 px-4 py-3 bg-zinc-50 border-b border-zinc-100">
                <div class="flex items-center gap-3 flex-wrap text-sm">

                    {{-- Country badge --}}
                    <span class="simo-badge bg-indigo-50 text-indigo-700">{{ $norma->pais }}</span>

                    {{-- Estado badge --}}
                    @if($norma->estado_extraccion === 'requiere_revision')
                        <span class="simo-badge bg-amber-50 text-amber-700">Revisar</span>
                    @else
                        <span class="simo-badge bg-violet-50 text-violet-700">Bulk / Detalle</span>
                    @endif

                    {{-- Fecha publicación --}}
                    @if($norma->fecha_publicacion)
                        <span class="text-zinc-500 text-xs">{{ $norma->fecha_publicacion->format('d/m/Y') }}</span>
                    @endif

                    {{-- Decree number → PDF link --}}
                    @if($norma->numero_decreto)
                        @if($norma->pdf_url)
                            <a href="{{ $norma->pdf_url }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="text-indigo-600 hover:underline text-xs font-medium"
                            >DS {{ $norma->numero_decreto }}</a>
                        @else
                            <span class="text-zinc-500 text-xs font-medium">DS {{ $norma->numero_decreto }}</span>
                        @endif
                    @endif

                    {{-- Event count --}}
                    @if($norma->eventosPep->isNotEmpty())
                        <span class="simo-badge bg-emerald-50 text-emerald-700">
                            {{ $norma->eventosPep->count() }} {{ $norma->eventosPep->count() === 1 ? 'nombramiento' : 'nombramientos' }}
                        </span>
                    @endif
                </div>

                {{-- Action buttons --}}
                <div class="flex items-center gap-2 shrink-0">
                    <button
                        wire:click="toggleForm({{ $norma->id }})"
                        class="simo-btn bg-indigo-50 border border-indigo-200 text-indigo-700 hover:bg-indigo-100 text-xs"
                    >
                        {{ $formNormaId === $norma->id ? 'Cerrar' : 'Agregar nombramiento' }}
                    </button>
                    <button
                        wire:click="descartar({{ $norma->id }})"
                        wire:confirm="¿Descartar este decreto? Quedará fuera de la cola de revisión."
                        class="simo-btn bg-zinc-50 border border-zinc-200 text-zinc-600 hover:bg-zinc-100 text-xs"
                    >
                        Descartar
                    </button>
                    <button
                        wire:click="marcarResuelto({{ $norma->id }})"
                        wire:confirm="¿Marcar como resuelto? Indica que ya añadiste todos los nombramientos."
                        class="simo-btn bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100 text-xs"
                    >
                        Marcar resuelto
                    </button>
                </div>
            </div>

            {{-- Full sumario — the human reads this to decide --}}
            <div class="px-4 py-3">
                <p class="text-sm text-zinc-700 leading-relaxed">{{ $norma->sumario }}</p>
            </div>

            {{-- Events already added for this norma --}}
            @if($norma->eventosPep->isNotEmpty())
                <div class="px-4 pb-3">
                    <p class="text-xs font-semibold text-zinc-500 uppercase tracking-wide mb-1">Nombramientos añadidos</p>
                    <ul class="space-y-1">
                        @foreach($norma->eventosPep as $ev)
                            <li class="text-xs text-zinc-600 flex items-center gap-2">
                                <span class="simo-badge bg-emerald-50 text-emerald-700">aprobado</span>
                                <span class="font-medium">{{ $ev->persona_nombre }}</span>
                                <span class="text-zinc-400">—</span>
                                <span>{{ $ev->cargo }}</span>
                                @if($ev->interino)
                                    <span class="simo-badge bg-amber-50 text-amber-700">Interino</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ── Expandable manual-extraction form ──────────────────── --}}
            @if($formNormaId === $norma->id)
                <div class="border-t border-zinc-100 bg-indigo-50/40 px-4 py-4 space-y-3">
                    <p class="text-xs font-semibold text-indigo-700 uppercase tracking-wide">Agregar nombramiento manual</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-600 mb-1" for="personaNombre-{{ $norma->id }}">
                                Nombre de la persona <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="personaNombre-{{ $norma->id }}"
                                type="text"
                                wire:model="personaNombre"
                                placeholder="Ej. Juan Pérez García"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-800 placeholder-zinc-400 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-300"
                            >
                            @error('personaNombre')
                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-600 mb-1" for="cargo-{{ $norma->id }}">
                                Cargo <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="cargo-{{ $norma->id }}"
                                type="text"
                                wire:model="cargo"
                                placeholder="Ej. Ministro de Defensa"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-800 placeholder-zinc-400 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-300"
                            >
                            @error('cargo')
                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            id="interino-{{ $norma->id }}"
                            type="checkbox"
                            wire:model="interino"
                            class="h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-300"
                        >
                        <label for="interino-{{ $norma->id }}" class="text-sm text-zinc-600">Interino</label>
                    </div>

                    <div>
                        <button
                            wire:click="agregarEvento({{ $norma->id }})"
                            class="simo-btn bg-indigo-600 text-white hover:bg-indigo-700 text-sm"
                        >
                            Agregar nombramiento
                        </button>
                    </div>
                </div>
            @endif

        </div>
        @endforeach
    </div>

    {{-- ── Paginación ───────────────────────────────────────────────────── --}}
    @if($normas->hasPages())
        <div class="pt-1">
            {{ $normas->links() }}
        </div>
    @endif

    @endif

</div>
