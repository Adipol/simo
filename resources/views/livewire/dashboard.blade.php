<div class="space-y-6" wire:poll.15s>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

        <div class="simo-card flex flex-col gap-1">
            <span class="text-xs font-medium text-gray-400">Resultados hoy</span>
            <span class="text-2xl font-bold text-gray-900">{{ number_format($resultadosHoy) }}</span>
            @if($resultadosHoyPorCat->isNotEmpty())
                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                    @foreach($resultadosHoyPorCat as $cat => $n)
                        <span class="text-[10px] font-medium">
                            <span class="{{ $cat === 'PEP' ? 'text-indigo-500' : 'text-amber-500' }}">{{ $cat }}</span>
                            <span class="text-gray-500">{{ number_format($n) }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
            <span class="text-xs text-gray-400">Total acumulado: {{ number_format($totalResultados) }}</span>
        </div>

        <div class="simo-card flex flex-col gap-1">
            <span class="text-xs font-medium text-gray-400">Sin leer</span>
            <span class="text-2xl font-bold {{ $resultadosSinLeer > 0 ? 'text-amber-500' : 'text-gray-300' }}">
                {{ number_format($resultadosSinLeer) }}
            </span>
            <span class="text-xs text-gray-400">Pendientes de revision</span>
        </div>

        <div class="simo-card flex flex-col gap-1">
            <span class="text-xs font-medium text-gray-400">Cambios PEP</span>
            <span class="text-2xl font-bold {{ $cambiosSinRevisar > 0 ? 'text-rose-500' : 'text-gray-300' }}">
                {{ number_format($cambiosSinRevisar) }}
            </span>
            <span class="text-xs text-gray-400">Sin revisar</span>
        </div>

        <div class="simo-card flex flex-col gap-1">
            <span class="text-xs font-medium text-gray-400">Sitios / Fuentes</span>
            <span class="text-2xl font-bold text-gray-900">{{ $totalSitios }}<span class="text-gray-300 font-normal"> / </span>{{ $totalFuentes }}</span>
            <span class="text-xs text-gray-400">Activos</span>
        </div>
    </div>

    {{-- Estado de scripts --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach([['Scraper', $scraperEjecutando, $scraperLog, 'items_resultado', 'Resultados'], ['PEP Monitor', $pepEjecutando, $pepLog, 'items_resultado', 'Cambios']] as [$nombre, $ejecutando, $log, $campo, $etiqueta])
        <div class="simo-card">
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-semibold text-gray-800">{{ $nombre }}</span>
                @if($ejecutando)
                    <span class="simo-badge bg-emerald-50 text-emerald-600">
                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                        Ejecutando
                    </span>
                @else
                    <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200">Inactivo</span>
                @endif
            </div>
            @if($log)
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-[10px] text-gray-400 uppercase tracking-wide mb-0.5">Ultima ejecucion</div>
                        <div class="text-xs font-medium text-gray-700">{{ $log->inicio->diffForHumans() }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] text-gray-400 uppercase tracking-wide mb-0.5">Estado</div>
                        <div class="text-xs font-medium {{ $log->estado === 'error' ? 'text-rose-600' : ($log->estado === 'completado' ? 'text-emerald-600' : 'text-amber-600') }}">
                            {{ ucfirst($log->estado) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-[10px] text-gray-400 uppercase tracking-wide mb-0.5">{{ $etiqueta }}</div>
                        <div class="text-xs font-medium text-gray-700">{{ $log->$campo }}</div>
                    </div>
                    @if($log->duracion_segundos)
                    <div>
                        <div class="text-[10px] text-gray-400 uppercase tracking-wide mb-0.5">Duracion</div>
                        <div class="text-xs font-medium text-gray-700">{{ round($log->duracion_segundos, 1) }}s</div>
                    </div>
                    @endif
                </div>
            @else
                <p class="text-xs text-gray-400">Sin registros aun.</p>
            @endif
        </div>
        @endforeach
    </div>

    {{-- Ultimos resultados y cambios --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

        {{-- Ultimos resultados --}}
        <div class="simo-card p-0 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100">
                <span class="text-sm font-semibold text-zinc-800">Ultimos resultados</span>
                <a href="{{ route('scraper.resultados') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Ver todos</a>
            </div>
            <ul class="divide-y divide-zinc-100">
                @forelse($ultimosResultados as $r)
                    <li class="px-5 py-3 hover:bg-zinc-50 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <span class="text-xs font-semibold text-indigo-600">{{ $r->keyword }}</span>
                                @if($r->titulo)
                                    <p class="text-xs text-gray-600 mt-0.5 truncate">{{ Str::limit($r->titulo, 65) }}</p>
                                @endif
                                <p class="text-[10px] text-gray-400 truncate mt-0.5">{{ Str::limit($r->url, 60) }}</p>
                            </div>
                            <span class="shrink-0 text-[10px] text-gray-400 mt-0.5">{{ $r->fecha_encontrado->format('d/m H:i') }}</span>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-xs text-gray-400 text-center">Sin resultados aun.</li>
                @endforelse
            </ul>
        </div>

        {{-- Ultimos cambios PEP --}}
        <div class="simo-card p-0 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100">
                <span class="text-sm font-semibold text-zinc-800">Ultimos cambios PEP</span>
                <a href="{{ route('pep.cambios') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Ver todos</a>
            </div>
            <ul class="divide-y divide-zinc-100">
                @forelse($ultimosCambios as $c)
                    <li class="px-5 py-3 hover:bg-zinc-50 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-gray-700 truncate">
                                    {{ $c->fuente?->nombre ?? $c->fuente?->url ?? 'Fuente #'.$c->fuente_id }}
                                </p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-[10px] text-emerald-600 font-medium">+{{ $c->lineas_nuevas }}</span>
                                    <span class="text-[10px] text-rose-500 font-medium">-{{ $c->lineas_quitadas }}</span>
                                    @if(!$c->revisado)
                                        <span class="simo-badge bg-amber-50 text-amber-600" style="font-size:9px">pendiente</span>
                                    @endif
                                </div>
                            </div>
                            <span class="shrink-0 text-[10px] text-gray-400 mt-0.5">{{ $c->fecha->format('d/m H:i') }}</span>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-6 text-xs text-gray-400 text-center">Sin cambios aun.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
