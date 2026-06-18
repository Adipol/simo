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
    </div>

    {{-- ── Empty state ─────────────────────────────────────────────────── --}}
    @if($eventos->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <svg class="w-12 h-12 text-zinc-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm font-medium text-zinc-500">No hay eventos pendientes de revisión.</p>
            @if($pais !== '')
                <button wire:click="$set('pais', '')" class="mt-2 text-xs text-indigo-600 hover:underline">
                    Ver todos los países
                </button>
            @endif
        </div>
    @else

    {{-- ── Tabla de eventos ─────────────────────────────────────────────── --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-100 bg-zinc-50 text-xs font-semibold text-zinc-500 uppercase tracking-wide">
                    <th class="px-4 py-3 text-left">País</th>
                    <th class="px-4 py-3 text-left">Persona</th>
                    <th class="px-4 py-3 text-left">Cargo</th>
                    <th class="px-4 py-3 text-left">Categoría</th>
                    <th class="px-4 py-3 text-left">Tipo</th>
                    <th class="px-4 py-3 text-left">Decreto</th>
                    <th class="px-4 py-3 text-left">Interino</th>
                    <th class="px-4 py-3 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach($eventos as $evento)
                <tr wire:key="evento-{{ $evento->id }}" class="hover:bg-zinc-50 transition-colors">
                    <td class="px-4 py-3">
                        <span class="simo-badge bg-indigo-50 text-indigo-700">{{ $evento->pais }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-zinc-800">{{ $evento->persona_nombre }}</p>
                    </td>
                    <td class="px-4 py-3 text-zinc-600">{{ $evento->cargo }}</td>
                    <td class="px-4 py-3">
                        @if($evento->cargo_categoria)
                            <span class="simo-badge bg-violet-50 text-violet-700">{{ $evento->cargo_categoria }}</span>
                        @else
                            <span class="text-zinc-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="simo-badge {{ $evento->tipo_evento === 'designacion' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600' }}">
                            {{ $evento->tipo_evento }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-zinc-500 text-xs">
                        @if($evento->gacetaNorma?->numero_decreto)
                            DS {{ $evento->gacetaNorma->numero_decreto }}
                            @if($evento->gacetaNorma->fecha_publicacion)
                                <br><span class="text-zinc-400">{{ $evento->gacetaNorma->fecha_publicacion->format('d/m/Y') }}</span>
                            @endif
                        @else
                            <span class="text-zinc-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if($evento->interino)
                            <span class="simo-badge bg-amber-50 text-amber-700">Interino</span>
                        @else
                            <span class="text-zinc-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-2">
                            <button
                                wire:click="aprobar({{ $evento->id }})"
                                wire:confirm="¿Aprobar este evento PEP?"
                                class="simo-btn bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100 text-xs"
                            >
                                Aprobar
                            </button>
                            <button
                                wire:click="rechazar({{ $evento->id }})"
                                wire:confirm="¿Rechazar este evento PEP?"
                                class="simo-btn bg-rose-50 border border-rose-200 text-rose-600 hover:bg-rose-100 text-xs"
                            >
                                Rechazar
                            </button>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── Paginación ───────────────────────────────────────────────────── --}}
    @if($eventos->hasPages())
        <div class="pt-1">
            {{ $eventos->links() }}
        </div>
    @endif

    @endif

</div>
