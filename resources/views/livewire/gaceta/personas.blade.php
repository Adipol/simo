<div class="space-y-4">

    {{-- ── Filtros ─────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-2 flex-wrap">
        <input
            type="text"
            wire:model.live.debounce.400ms="buscar"
            placeholder="Buscar por nombre…"
            class="simo-select flex-1 min-w-[200px]"
        >
        <select wire:model.live="pais" class="simo-select">
            <option value="">Todos los países</option>
            <option value="BO">Bolivia</option>
            <option value="HN">Honduras</option>
            <option value="SV">El Salvador</option>
            <option value="NI">Nicaragua</option>
            <option value="PY">Paraguay</option>
            <option value="GT">Guatemala</option>
        </select>
    </div>

    {{-- ── Empty state ──────────────────────────────────────────────── --}}
    @if($personas->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <svg class="w-12 h-12 text-zinc-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <p class="text-sm font-medium text-zinc-500">No se encontraron personas PEP.</p>
            @if($buscar !== '' || $pais !== '')
                <button wire:click="$set('buscar', '')" class="mt-2 text-xs text-indigo-600 hover:underline">
                    Limpiar filtros
                </button>
            @endif
        </div>
    @else

    {{-- ── Tabla de personas ────────────────────────────────────────── --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-100 bg-zinc-50 text-xs font-semibold text-zinc-500 uppercase tracking-wide">
                    <th class="px-4 py-3 text-left">Nombre</th>
                    <th class="px-4 py-3 text-left">Cargo titular vigente</th>
                    <th class="px-4 py-3 text-center">Designaciones</th>
                    <th class="px-4 py-3 text-left">Período</th>
                    <th class="px-4 py-3 text-center">Estado PEP</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach($personas as $persona)
                <tr
                    wire:key="persona-{{ $persona->persona_nombre_normalizado }}"
                    wire:click="seleccionar('{{ $persona->persona_nombre_normalizado }}')"
                    class="cursor-pointer hover:bg-zinc-50 transition-colors {{ $personaSeleccionada === $persona->persona_nombre_normalizado ? 'bg-indigo-50/50' : '' }}"
                >
                    <td class="px-4 py-3">
                        <p class="font-medium text-zinc-800">{{ $persona->persona_nombre }}</p>
                        <p class="text-xs text-zinc-400 mt-0.5">{{ $persona->persona_nombre_normalizado }}</p>
                    </td>
                    <td class="px-4 py-3 text-zinc-700">
                        @if($persona->cargo_titular)
                            {{ $persona->cargo_titular }}
                        @else
                            <span class="text-zinc-400 text-xs italic">Solo designaciones interinas</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="simo-badge bg-zinc-100 text-zinc-600 font-mono">
                            {{ $persona->total_designaciones }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-zinc-500 text-xs">
                        @if($persona->desde)
                            {{ \Illuminate\Support\Carbon::parse($persona->desde)->format('d/m/Y') }}
                            @if($persona->hasta && $persona->hasta !== $persona->desde)
                                — {{ \Illuminate\Support\Carbon::parse($persona->hasta)->format('d/m/Y') }}
                            @endif
                        @else
                            <span class="text-zinc-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="simo-badge bg-amber-50 text-amber-700 font-semibold text-xs">
                            PEP — designado en los últimos 5 años
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right text-zinc-400">
                        <svg class="w-4 h-4 inline transition-transform {{ $personaSeleccionada === $persona->persona_nombre_normalizado ? 'rotate-180' : '' }}"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </td>
                </tr>

                {{-- ── Detail panel ─────────────────────────────────── --}}
                @if($personaSeleccionada === $persona->persona_nombre_normalizado && $detalle->isNotEmpty())
                <tr wire:key="detalle-{{ $persona->persona_nombre_normalizado }}">
                    <td colspan="6" class="px-4 py-0">
                        <div class="border-t border-indigo-100 bg-indigo-50/30 px-2 py-4 rounded-b-lg space-y-3">

                            {{-- Cargo titular highlight --}}
                            <div class="flex items-start gap-3">
                                <div class="flex-1">
                                    <p class="text-xs font-semibold text-indigo-700 uppercase tracking-wide mb-1">
                                        Cargo titular vigente
                                    </p>
                                    @if($cargoTitular)
                                        <p class="text-base font-semibold text-zinc-800">{{ $cargoTitular }}</p>
                                    @else
                                        <p class="text-sm text-zinc-400 italic">
                                            Solo designaciones interinas — sin cargo titular en registro
                                        </p>
                                    @endif
                                </div>
                                <span class="simo-badge bg-amber-100 text-amber-800 font-semibold whitespace-nowrap">
                                    PEP — designado en los últimos 5 años
                                </span>
                            </div>

                            {{-- Full history table --}}
                            <div class="overflow-x-auto rounded-lg border border-indigo-100 bg-white">
                                <table class="min-w-full text-xs">
                                    <thead>
                                        <tr class="border-b border-zinc-100 bg-zinc-50 font-semibold text-zinc-500 uppercase tracking-wide">
                                            <th class="px-3 py-2 text-left">Fecha</th>
                                            <th class="px-3 py-2 text-left">Cargo</th>
                                            <th class="px-3 py-2 text-center">Tipo</th>
                                            <th class="px-3 py-2 text-left">Decreto</th>
                                            <th class="px-3 py-2 text-left">País</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-50">
                                        @foreach($detalle as $evento)
                                        <tr wire:key="ev-{{ $evento->id }}" class="hover:bg-zinc-50">
                                            <td class="px-3 py-2 text-zinc-500 whitespace-nowrap">
                                                {{ $evento->gacetaNorma?->fecha_publicacion?->format('d/m/Y') ?? '—' }}
                                            </td>
                                            <td class="px-3 py-2 text-zinc-700 font-medium">
                                                {{ $evento->cargo }}
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                @if($evento->interino)
                                                    <span class="simo-badge bg-amber-50 text-amber-700">Interino</span>
                                                @else
                                                    <span class="simo-badge bg-emerald-50 text-emerald-700">Titular</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-zinc-500">
                                                @if($evento->gacetaNorma?->numero_decreto)
                                                    @if($evento->gacetaNorma->pdf_url)
                                                        <a href="{{ $evento->gacetaNorma->pdf_url }}"
                                                           target="_blank"
                                                           rel="noopener noreferrer"
                                                           class="text-indigo-600 hover:underline"
                                                           wire:click.stop
                                                        >DS {{ $evento->gacetaNorma->numero_decreto }}</a>
                                                    @else
                                                        DS {{ $evento->gacetaNorma->numero_decreto }}
                                                    @endif
                                                @else
                                                    <span class="text-zinc-300">—</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2">
                                                <span class="simo-badge bg-indigo-50 text-indigo-700">
                                                    {{ $evento->pais }}
                                                </span>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                @endif

                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── Paginación ────────────────────────────────────────────────── --}}
    @if($personas->hasPages())
        <div class="pt-1">
            {{ $personas->links() }}
        </div>
    @endif

    @endif

</div>
