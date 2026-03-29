<div class="space-y-4">

    {{-- Filtros --}}
    <div class="flex items-center gap-2 flex-wrap">
        <select wire:model.live="filtroFuente" class="simo-select flex-1 max-w-xs">
            <option value="">Todas las fuentes</option>
            @foreach($fuentes as $f)
                <option value="{{ $f->id }}">{{ $f->nombre ?? $f->organismo ?? 'Fuente #'.$f->id }}</option>
            @endforeach
        </select>
        <select wire:model.live="filtroRevisado" class="simo-select">
            <option value="">Todos</option>
            <option value="0">Sin revisar</option>
            <option value="1">Revisados</option>
        </select>
    </div>

    {{-- Lista de cambios --}}
    <div class="space-y-3">
        @forelse($cambios as $c)
            <div class="simo-card p-0 overflow-hidden {{ !$c->revisado ? 'border-l-4 border-amber-400' : '' }}">
                <div class="px-5 py-4 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-800">
                            {{ $c->fuente?->nombre ?? $c->fuente?->organismo ?? 'Fuente #'.$c->fuente_id }}
                        </p>
                        <div class="flex items-center gap-3 mt-1 flex-wrap">
                            <span class="text-xs text-gray-400">{{ $c->fecha->format('d/m/Y H:i') }}</span>
                            <span class="text-xs text-emerald-600 font-medium">+{{ $c->lineas_nuevas }} nuevas</span>
                            <span class="text-xs text-rose-500 font-medium">-{{ $c->lineas_quitadas }} quitadas</span>
                            @if($c->posibles_peps)
                                <span class="simo-badge bg-violet-50 text-violet-700">
                                    {{ count($c->posiblesPepsArray()) }} posibles PEPs
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button wire:click="toggleDiff({{ $c->id }})"
                            class="simo-btn bg-gray-50 border border-gray-200 text-gray-600 hover:bg-gray-100">
                            {{ $verDiffId === $c->id ? 'Ocultar' : 'Ver diff' }}
                        </button>
                        @if(!$c->revisado)
                            <button wire:click="marcarRevisado({{ $c->id }})"
                                class="simo-btn-primary">
                                Marcar revisado
                            </button>
                        @else
                            <span class="simo-badge bg-emerald-50 text-emerald-600">Revisado</span>
                        @endif
                    </div>
                </div>

                {{-- Panel diff --}}
                @if($verDiffId === $c->id && $cambioDetalle)
                    <div class="border-t border-gray-100">
                        @if($cambioDetalle->posibles_peps)
                            <div class="px-5 py-3 bg-violet-50/60 border-b border-violet-100">
                                <p class="text-xs font-semibold text-violet-700 mb-2">Posibles PEPs detectados</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($cambioDetalle->posiblesPepsArray() as $pep)
                                        <span class="simo-badge bg-violet-100 text-violet-800">{{ $pep }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if($cambioDetalle->diff_texto)
                            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                                <table class="min-w-full text-xs font-mono">
                                    <tbody>
                                        @foreach($cambioDetalle->parsedDiff() as $line)
                                            @if($line['type'] === 'added')
                                                <tr class="bg-emerald-50">
                                                    <td class="pl-4 pr-2 text-emerald-500 select-none w-5">+</td>
                                                    <td class="pr-5 py-0.5 text-emerald-800 whitespace-pre-wrap break-all">{{ $line['text'] }}</td>
                                                </tr>
                                            @elseif($line['type'] === 'removed')
                                                <tr class="bg-rose-50">
                                                    <td class="pl-4 pr-2 text-rose-400 select-none w-5">-</td>
                                                    <td class="pr-5 py-0.5 text-rose-700 whitespace-pre-wrap break-all line-through opacity-60">{{ $line['text'] }}</td>
                                                </tr>
                                            @else
                                                <tr>
                                                    <td class="pl-4 pr-2 text-gray-200 select-none w-5"> </td>
                                                    <td class="pr-5 py-0.5 text-gray-500 whitespace-pre-wrap break-all">{{ $line['text'] }}</td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="px-5 py-4 text-xs text-gray-400">Sin datos de diff disponibles.</p>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="simo-card py-12 text-center text-sm text-gray-400">
                Sin cambios registrados.
            </div>
        @endforelse
    </div>

    <div>{{ $cambios->links() }}</div>
</div>
