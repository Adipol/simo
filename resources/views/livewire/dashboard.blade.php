<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Dashboard</h1>

    {{-- Tarjetas de resumen --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Resultados hoy</div>
            <div class="text-3xl font-bold text-blue-600">{{ number_format($resultadosHoy) }}</div>
            <div class="text-xs text-gray-400 mt-1">Total: {{ number_format($totalResultados) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Sin leer</div>
            <div class="text-3xl font-bold {{ $resultadosSinLeer > 0 ? 'text-amber-500' : 'text-gray-400' }}">
                {{ number_format($resultadosSinLeer) }}
            </div>
            <div class="text-xs text-gray-400 mt-1">Resultados pendientes</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Cambios PEP</div>
            <div class="text-3xl font-bold {{ $cambiosSinRevisar > 0 ? 'text-red-500' : 'text-gray-400' }}">
                {{ number_format($cambiosSinRevisar) }}
            </div>
            <div class="text-xs text-gray-400 mt-1">Sin revisar</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">Sitios / Fuentes</div>
            <div class="text-3xl font-bold text-gray-700">{{ $totalSitios }} / {{ $totalFuentes }}</div>
            <div class="text-xs text-gray-400 mt-1">Activos</div>
        </div>
    </div>

    {{-- Estado de scripts --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        {{-- Scraper --}}
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="font-semibold text-gray-700">Scraper</h2>
                @if($scraperEjecutando)
                    <span class="inline-flex items-center gap-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        Ejecutando
                    </span>
                @else
                    <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">Inactivo</span>
                @endif
            </div>
            @if($scraperLog)
                <div class="text-xs text-gray-500 space-y-0.5">
                    <div>Ultima ejecucion: <span class="text-gray-700">{{ $scraperLog->inicio->diffForHumans() }}</span></div>
                    <div>Estado: <span class="font-medium {{ $scraperLog->estado === 'error' ? 'text-red-600' : 'text-gray-700' }}">{{ $scraperLog->estado }}</span></div>
                    @if($scraperLog->duracion_segundos)
                        <div>Duracion: <span class="text-gray-700">{{ round($scraperLog->duracion_segundos, 1) }}s</span></div>
                    @endif
                    <div>Resultados: <span class="text-gray-700">{{ $scraperLog->items_resultado }}</span></div>
                </div>
            @else
                <p class="text-xs text-gray-400">Sin registros aun.</p>
            @endif
        </div>

        {{-- PEP Monitor --}}
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="font-semibold text-gray-700">PEP Monitor</h2>
                @if($pepEjecutando)
                    <span class="inline-flex items-center gap-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        Ejecutando
                    </span>
                @else
                    <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">Inactivo</span>
                @endif
            </div>
            @if($pepLog)
                <div class="text-xs text-gray-500 space-y-0.5">
                    <div>Ultima ejecucion: <span class="text-gray-700">{{ $pepLog->inicio->diffForHumans() }}</span></div>
                    <div>Estado: <span class="font-medium {{ $pepLog->estado === 'error' ? 'text-red-600' : 'text-gray-700' }}">{{ $pepLog->estado }}</span></div>
                    @if($pepLog->duracion_segundos)
                        <div>Duracion: <span class="text-gray-700">{{ round($pepLog->duracion_segundos, 1) }}s</span></div>
                    @endif
                    <div>Cambios encontrados: <span class="text-gray-700">{{ $pepLog->items_resultado }}</span></div>
                </div>
            @else
                <p class="text-xs text-gray-400">Sin registros aun.</p>
            @endif
        </div>
    </div>

    {{-- Ultimos resultados y cambios --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Ultimos resultados scraping --}}
        <div class="bg-white rounded-lg shadow">
            <div class="flex items-center justify-between px-4 py-3 border-b">
                <h2 class="font-semibold text-gray-700 text-sm">Ultimos resultados</h2>
                <a href="{{ route('scraper.resultados') }}" class="text-xs text-blue-600 hover:underline">Ver todos</a>
            </div>
            <ul class="divide-y divide-gray-100">
                @forelse($ultimosResultados as $r)
                    <li class="px-4 py-2 text-xs">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <span class="font-medium text-gray-700">{{ $r->keyword }}</span>
                                @if($r->titulo)
                                    <span class="text-gray-500"> — {{ Str::limit($r->titulo, 60) }}</span>
                                @endif
                                <div class="text-gray-400 truncate">{{ Str::limit($r->url, 70) }}</div>
                            </div>
                            <span class="shrink-0 text-gray-400">{{ $r->fecha_encontrado->format('d/m H:i') }}</span>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-4 text-xs text-gray-400 text-center">Sin resultados aun.</li>
                @endforelse
            </ul>
        </div>

        {{-- Ultimos cambios PEP --}}
        <div class="bg-white rounded-lg shadow">
            <div class="flex items-center justify-between px-4 py-3 border-b">
                <h2 class="font-semibold text-gray-700 text-sm">Ultimos cambios PEP</h2>
                <a href="{{ route('pep.cambios') }}" class="text-xs text-blue-600 hover:underline">Ver todos</a>
            </div>
            <ul class="divide-y divide-gray-100">
                @forelse($ultimosCambios as $c)
                    <li class="px-4 py-2 text-xs">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <span class="font-medium text-gray-700">{{ $c->fuente?->nombre ?? $c->fuente?->url ?? 'Fuente #'.$c->fuente_id }}</span>
                                <div class="text-gray-500">
                                    <span class="text-green-600">+{{ $c->lineas_nuevas }}</span>
                                    /
                                    <span class="text-red-600">-{{ $c->lineas_quitadas }}</span>
                                    @if(!$c->revisado)
                                        <span class="ml-1 bg-amber-100 text-amber-700 px-1 rounded">pendiente</span>
                                    @endif
                                </div>
                            </div>
                            <span class="shrink-0 text-gray-400">{{ $c->fecha->format('d/m H:i') }}</span>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-4 text-xs text-gray-400 text-center">Sin cambios aun.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
