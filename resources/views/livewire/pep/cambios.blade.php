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
        <select wire:model.live="filtroConPersona" class="simo-select">
            <option value="si">Con persona detectada</option>
            <option value="no">Sin persona detectada</option>
            <option value="">Todos los registros</option>
        </select>
        <select wire:model.live="filtroRiesgo" class="simo-select">
            <option value="">Todos los riesgos</option>
            <option value="alto">Riesgo alto</option>
            <option value="medio">Riesgo medio</option>
            <option value="bajo">Riesgo bajo</option>
        </select>
    </div>

    {{-- Banner contextual --}}
    @if($filtroConPersona === 'si')
        <div class="flex items-center gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm text-indigo-700">
            <svg class="h-4 w-4 shrink-0 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>Mostrando solo cambios con personas detectadas por Gemini.</span>
            <button wire:click="$set('filtroConPersona', '')" class="ml-auto text-xs font-medium text-indigo-600 hover:text-indigo-800 hover:underline">
                Ver todos
            </button>
        </div>
    @endif

    {{-- Lista de cambios --}}
    <div class="space-y-3">
        @forelse($cambios as $c)
            <div wire:key="cambio-{{ $c->id }}" class="simo-card p-0 overflow-hidden {{ !$c->revisado ? 'border-l-4 border-amber-400' : '' }} {{ $c->esMuted() ? 'opacity-60 border-l-gray-300' : '' }}">
                <div class="px-5 py-4 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-800">
                            {{ $c->fuente?->nombre ?? $c->fuente?->organismo ?? 'Fuente #'.$c->fuente_id }}
                            @if($c->fuente?->url)
                                <a href="{{ $c->fuente->url }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center ml-2 text-xs font-normal text-indigo-500 hover:text-indigo-700 hover:underline">
                                    <svg class="w-3.5 h-3.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    Ver fuente
                                </a>
                            @endif
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
                            {{-- MAE Badge if Gemini detected it --}}
                            @if(($c->gemini_analisis_json['es_mae'] ?? false))
                                <span class="simo-badge bg-red-100 text-red-700 border-red-200">MAE</span>
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
                @if($verDiffId === $c->id && $this->cambioDetalle)
                    <div class="border-t border-gray-100">
                        @if($this->cambioDetalle->posibles_peps)
                            <div class="px-5 py-3 bg-violet-50/60 border-b border-violet-100">
                                <p class="text-xs font-semibold text-violet-700 mb-2">Posibles PEPs detectados</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($this->cambioDetalle->posiblesPepsArray() as $pep)
                                        <span class="simo-badge bg-violet-100 text-violet-800">{{ $pep }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Análisis Gemini --}}
                        @if($this->cambioDetalle->gemini_analyzed && $this->cambioDetalle->gemini_analisis_json)
                            @php $analisis = $this->cambioDetalle->gemini_analisis_json; @endphp
                            <div class="px-5 py-3 bg-indigo-50/60 border-b border-indigo-100">
                                <p class="text-xs font-semibold text-indigo-700 mb-2">Análisis Gemini</p>
                                <div class="grid grid-cols-2 gap-3 text-xs">
                                    @if($analisis['persona_removida'] ?? null)
                                        <div><span class="text-gray-500">Removido:</span> <span class="font-medium text-gray-800">{{ $analisis['persona_removida'] }}</span></div>
                                    @endif
                                    @if($analisis['persona_nueva'] ?? null)
                                        <div><span class="text-gray-500">Nuevo:</span> <span class="font-medium text-gray-800">{{ $analisis['persona_nueva'] }}</span></div>
                                    @endif
                                    <div><span class="text-gray-500">Cargo:</span> <span class="font-medium">{{ $analisis['cargo'] ?? '—' }}</span></div>
                                    <div class="flex items-center gap-2">
                                        @if($analisis['es_mae'] ?? false)
                                            <span class="simo-badge bg-red-100 text-red-700 border-red-200">MAE</span>
                                        @else
                                            <span class="simo-badge bg-gray-100 text-gray-500">No MAE</span>
                                        @endif
                                        <span class="simo-badge {{ $this->riesgoColor($analisis['riesgo'] ?? 'bajo') }}">Riesgo: {{ ucfirst($analisis['riesgo'] ?? 'bajo') }}</span>
                                    </div>
                                </div>
                                @if($analisis['analisis'] ?? null)
                                    <p class="text-xs text-gray-600 mt-2 bg-white rounded p-2">{{ $analisis['analisis'] }}</p>
                                @endif
                            </div>
                        @endif

                        @if($this->cambioDetalle->diff_texto)
                            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                                <table class="min-w-full text-xs font-mono">
                                    <tbody>
                                        @foreach($this->cambioDetalle->parsedDiff() as $line)
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
