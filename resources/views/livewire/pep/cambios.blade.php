<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Cambios PEP</h1>
        <a href="{{ route('pep.fuentes') }}" class="text-sm text-blue-600 hover:underline">Gestionar fuentes</a>
    </div>

    {{-- Filtros --}}
    <div class="bg-white rounded-lg shadow p-3 mb-4 flex gap-2 flex-wrap">
        <select wire:model.live="filtroFuente" class="border rounded px-2 py-1.5 text-sm flex-1">
            <option value="">Todas las fuentes</option>
            @foreach($fuentes as $f)
                <option value="{{ $f->id }}">{{ $f->nombre ?? $f->organismo ?? 'Fuente #'.$f->id }}</option>
            @endforeach
        </select>
        <select wire:model.live="filtroRevisado" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todos</option>
            <option value="0">Sin revisar</option>
            <option value="1">Revisados</option>
        </select>
    </div>

    {{-- Lista de cambios --}}
    <div class="space-y-3">
        @forelse($cambios as $c)
            <div class="bg-white rounded-lg shadow overflow-hidden {{ !$c->revisado ? 'border-l-4 border-amber-400' : '' }}">
                <div class="px-4 py-3 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="font-medium text-gray-800 text-sm">
                            {{ $c->fuente?->nombre ?? $c->fuente?->organismo ?? 'Fuente #'.$c->fuente_id }}
                        </div>
                        <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-3">
                            <span>{{ $c->fecha->format('d/m/Y H:i') }}</span>
                            <span class="text-green-600 font-medium">+{{ $c->lineas_nuevas }} nuevas</span>
                            <span class="text-red-600 font-medium">-{{ $c->lineas_quitadas }} quitadas</span>
                            @if($c->posibles_peps)
                                <span class="bg-purple-100 text-purple-700 px-1.5 rounded text-xs">
                                    {{ count($c->posiblesPepsArray()) }} posibles PEPs
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button wire:click="toggleDiff({{ $c->id }})"
                                class="text-xs text-blue-600 hover:underline">
                            {{ $verDiffId === $c->id ? 'Ocultar diff' : 'Ver diff' }}
                        </button>
                        @if(!$c->revisado)
                            <button wire:click="marcarRevisado({{ $c->id }})"
                                    class="text-xs bg-amber-500 hover:bg-amber-600 text-white px-2 py-1 rounded">
                                Marcar revisado
                            </button>
                        @else
                            <span class="text-xs text-gray-400">Revisado</span>
                        @endif
                    </div>
                </div>

                {{-- Panel del diff --}}
                @if($verDiffId === $c->id && $cambioDetalle)
                    <div class="border-t">
                        {{-- Posibles PEPs --}}
                        @if($cambioDetalle->posibles_peps)
                            <div class="bg-purple-50 px-4 py-2 border-b">
                                <div class="text-xs font-semibold text-purple-700 mb-1">Posibles PEPs detectados:</div>
                                <div class="flex flex-wrap gap-1">
                                    @foreach($cambioDetalle->posiblesPepsArray() as $pep)
                                        <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded text-xs">{{ $pep }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Diff visual --}}
                        @if($cambioDetalle->diff_texto)
                            <div class="overflow-x-auto max-h-96 overflow-y-auto">
                                <table class="min-w-full text-xs font-mono">
                                    <tbody>
                                        @foreach($cambioDetalle->parsedDiff() as $i => $line)
                                            @if($line['type'] === 'added')
                                                <tr class="bg-green-50">
                                                    <td class="pl-3 pr-1 text-green-600 select-none w-4">+</td>
                                                    <td class="pr-4 py-0.5 text-green-800 whitespace-pre-wrap break-all">{{ $line['text'] }}</td>
                                                </tr>
                                            @elseif($line['type'] === 'removed')
                                                <tr class="bg-red-50">
                                                    <td class="pl-3 pr-1 text-red-600 select-none w-4">-</td>
                                                    <td class="pr-4 py-0.5 text-red-800 whitespace-pre-wrap break-all line-through opacity-70">{{ $line['text'] }}</td>
                                                </tr>
                                            @else
                                                <tr class="bg-white">
                                                    <td class="pl-3 pr-1 text-gray-300 select-none w-4"> </td>
                                                    <td class="pr-4 py-0.5 text-gray-600 whitespace-pre-wrap break-all">{{ $line['text'] }}</td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="px-4 py-3 text-xs text-gray-400">Sin datos de diff disponibles.</p>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-lg shadow px-4 py-8 text-center text-gray-400">
                Sin cambios registrados.
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $cambios->links() }}</div>
</div>
