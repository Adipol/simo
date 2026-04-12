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

    {{-- ── ESTADÍSTICAS (collapsible, admin/supervisor only) ── --}}
    @can('ver dashboard estadisticas')
    <div class="border-t border-zinc-200 pt-4">

        {{-- Toggle button --}}
        <button
            wire:click="toggleEstadisticas"
            type="button"
            class="simo-btn simo-btn-primary flex items-center gap-2 mb-4"
            aria-expanded="{{ $mostrarEstadisticas ? 'true' : 'false' }}"
        >
            @if($mostrarEstadisticas)
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                </svg>
                Ocultar estadísticas
            @else
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
                Ver estadísticas
            @endif
        </button>

        @if($mostrarEstadisticas)

            {{-- Timestamp --}}
            <p class="text-[10px] text-zinc-400 mb-4">
                Actualizado: {{ now()->format('H:i:s') }}
                <span class="ml-2 text-zinc-300">(caché 5 min)</span>
            </p>

            {{-- Filter bar --}}
            <div class="simo-card mb-6">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] text-zinc-400 uppercase tracking-wide font-medium">Período</label>
                        <select wire:model.live="filtroDateRange" class="text-xs border border-zinc-200 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">Todo el tiempo</option>
                            <option value="today">Hoy</option>
                            <option value="week">Última semana</option>
                            <option value="month">Este mes</option>
                            <option value="quarter">Este trimestre</option>
                            <option value="year">Este año</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] text-zinc-400 uppercase tracking-wide font-medium">País</label>
                        <input
                            type="text"
                            wire:model.live="filtroPais"
                            placeholder="AR, CL, BO..."
                            class="text-xs border border-zinc-200 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 w-32"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] text-zinc-400 uppercase tracking-wide font-medium">Categoría</label>
                        <select wire:model.live="filtroCategoria" class="text-xs border border-zinc-200 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">Todas</option>
                            <option value="PEP">PEP</option>
                            <option value="OPI">OPI</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- KPI Cards (stats) --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

                <div class="simo-card flex flex-col gap-1">
                    <span class="text-xs font-medium text-zinc-400">Total PEPs</span>
                    @if($this->volumeMetrics->hasData)
                        <span class="text-2xl font-bold text-indigo-600">{{ number_format($this->volumeMetrics->totalPeps) }}</span>
                    @else
                        <span class="text-2xl font-bold text-zinc-300">0</span>
                    @endif
                    <span class="text-[10px] text-zinc-400">Detectados</span>
                </div>

                <div class="simo-card flex flex-col gap-1">
                    <span class="text-xs font-medium text-zinc-400">Total OPIs</span>
                    @if($this->volumeMetrics->hasData)
                        <span class="text-2xl font-bold text-amber-500">{{ number_format($this->volumeMetrics->totalOpis) }}</span>
                    @else
                        <span class="text-2xl font-bold text-zinc-300">0</span>
                    @endif
                    <span class="text-[10px] text-zinc-400">Detectados</span>
                </div>

                <div class="simo-card flex flex-col gap-1">
                    <span class="text-xs font-medium text-zinc-400">Precisión Gemini</span>
                    @if($this->precisionMetrics->hasData)
                        <span class="text-2xl font-bold text-emerald-600">{{ $this->precisionMetrics->overallAccuracy }}%</span>
                    @else
                        <span class="text-2xl font-bold text-zinc-300">N/A</span>
                    @endif
                    <span class="text-[10px] text-zinc-400">Basado en feedback</span>
                </div>

                <div class="simo-card flex flex-col gap-1">
                    <span class="text-xs font-medium text-zinc-400">Sin leer</span>
                    @if($this->volumeMetrics->hasData)
                        <span class="text-2xl font-bold {{ $this->volumeMetrics->unreadCount > 0 ? 'text-amber-500' : 'text-zinc-300' }}">
                            {{ number_format($this->volumeMetrics->unreadCount) }}
                        </span>
                    @else
                        <span class="text-2xl font-bold text-zinc-300">0</span>
                    @endif
                    <span class="text-[10px] text-zinc-400">Resultados</span>
                </div>
            </div>

            {{-- Trend Indicators --}}
            @php $trends = $this->trendIndicators; @endphp
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                @foreach([
                    ['label' => 'Tendencia PEPs', 'trend' => $trends->pepsTrend],
                    ['label' => 'Tendencia OPIs', 'trend' => $trends->opisTrend],
                    ['label' => 'Tendencia Feedback', 'trend' => $trends->feedbackTrend],
                ] as $indicator)
                <div class="simo-card flex items-center gap-4">
                    <div>
                        <span class="text-xs font-medium text-zinc-500">{{ $indicator['label'] }}</span>
                        <div class="flex items-center gap-2 mt-1">
                            @if($indicator['trend']['direction'] === 'up')
                                <span class="text-emerald-500" aria-label="Tendencia al alza">↑</span>
                                <span class="text-sm font-bold text-emerald-600">+{{ $indicator['trend']['delta_pct'] }}%</span>
                            @elseif($indicator['trend']['direction'] === 'down')
                                <span class="text-rose-500" aria-label="Tendencia a la baja">↓</span>
                                <span class="text-sm font-bold text-rose-600">{{ $indicator['trend']['delta_pct'] }}%</span>
                            @else
                                <span class="text-zinc-400" aria-label="Sin cambio">→</span>
                                <span class="text-sm font-bold text-zinc-500">0%</span>
                            @endif
                        </div>
                        <p class="text-[10px] text-zinc-400 mt-0.5">
                            Actual: {{ $indicator['trend']['current'] }} / Anterior: {{ $indicator['trend']['previous'] }}
                        </p>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Volume Trend Chart (wire:ignore + Alpine + Chart.js) --}}
            <div class="simo-card mb-6">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-sm font-semibold text-zinc-800">Volumen mensual (últimos 12 meses)</span>
                </div>
                @if($this->volumeMetrics->hasData)
                    <div
                        x-data="volumeChart(@js($this->volumeMetrics->monthlyTrend))"
                        wire:ignore
                    >
                        <canvas
                            x-ref="canvas"
                            aria-label="Gráfico de volumen mensual de detecciones"
                            role="img"
                            style="height:220px"
                        ></canvas>
                    </div>
                @else
                    <div class="text-center text-zinc-400 py-12 text-sm">Sin datos suficientes</div>
                @endif
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

                {{-- System Precision (buckets) --}}
                <div class="simo-card">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-sm font-semibold text-zinc-800">Precisión por confianza</span>
                    </div>
                    @if($this->precisionMetrics->hasData)
                        <div class="overflow-x-auto">
                            <table class="simo-table w-full text-xs">
                                <thead>
                                    <tr>
                                        <th scope="col" class="text-left">Rango</th>
                                        <th scope="col" class="text-right">Total</th>
                                        <th scope="col" class="text-right">Correctos</th>
                                        <th scope="col" class="text-right">Precisión</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->precisionMetrics->byBucket as $bucket)
                                    <tr>
                                        <td class="font-medium text-zinc-700">{{ $bucket['bucket'] }}</td>
                                        <td class="text-right text-zinc-600">{{ $bucket['total'] }}</td>
                                        <td class="text-right text-zinc-600">{{ $bucket['correctos'] }}</td>
                                        <td class="text-right font-semibold {{ $bucket['accuracy'] >= 80 ? 'text-emerald-600' : ($bucket['accuracy'] >= 60 ? 'text-amber-600' : 'text-rose-600') }}">
                                            {{ $bucket['accuracy'] }}%
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center text-zinc-400 py-8 text-sm">Sin datos suficientes</div>
                    @endif
                </div>

                {{-- Top Failing Positions --}}
                <div class="simo-card">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-sm font-semibold text-zinc-800">Cargos con más errores</span>
                        <span class="text-[10px] text-zinc-400">mín. 3 muestras</span>
                    </div>
                    @if(!empty($this->topFailingPositions))
                        <div class="overflow-x-auto">
                            <table class="simo-table w-full text-xs">
                                <thead>
                                    <tr>
                                        <th scope="col" class="text-left">Cargo</th>
                                        <th scope="col" class="text-right">Muestras</th>
                                        <th scope="col" class="text-right">Errores</th>
                                        <th scope="col" class="text-right">% Error</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->topFailingPositions as $pos)
                                    <tr>
                                        <td class="font-medium text-zinc-700 max-w-[160px] truncate">{{ $pos['cargo'] }}</td>
                                        <td class="text-right text-zinc-600">{{ $pos['total_muestras'] }}</td>
                                        <td class="text-right text-zinc-600">{{ $pos['total_errores'] }}</td>
                                        <td class="text-right font-semibold text-rose-600">{{ $pos['error_rate'] }}%</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center text-zinc-400 py-8 text-sm">Sin datos suficientes</div>
                    @endif
                </div>
            </div>

            {{-- Geographic Distribution --}}
            <div class="simo-card mb-6">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-semibold text-zinc-800">Distribución geográfica</span>
                </div>
                @if($this->geographicMetrics->hasData)
                    <div class="overflow-x-auto">
                        <table class="simo-table w-full text-xs">
                            <thead>
                                <tr>
                                    <th scope="col" class="text-left">País</th>
                                    <th scope="col" class="text-right">PEPs</th>
                                    <th scope="col" class="text-right">OPIs</th>
                                    <th scope="col" class="text-right">Avg Confianza</th>
                                    <th scope="col" class="text-right">% Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->geographicMetrics->byCountry as $country)
                                <tr>
                                    <td class="font-semibold text-zinc-700">{{ $country['pais'] }}</td>
                                    <td class="text-right text-indigo-600 font-medium">{{ number_format($country['peps_count']) }}</td>
                                    <td class="text-right text-amber-500 font-medium">{{ number_format($country['opis_count']) }}</td>
                                    <td class="text-right text-zinc-600">{{ $country['avg_confianza'] }}%</td>
                                    <td class="text-right {{ $country['error_rate'] > 20 ? 'text-rose-600' : 'text-zinc-500' }}">{{ $country['error_rate'] }}%</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center text-zinc-400 py-8 text-sm">Sin datos suficientes</div>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                {{-- Recent High-Confidence PEPs --}}
                <div class="simo-card p-0 overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100">
                        <span class="text-sm font-semibold text-zinc-800">PEPs alta confianza</span>
                        <span class="simo-badge bg-indigo-50 text-indigo-600">≥ 90%</span>
                    </div>
                    @if(!empty($this->recentActivity->highConfidencePeps))
                        <ul class="divide-y divide-zinc-100">
                            @foreach($this->recentActivity->highConfidencePeps as $pep)
                            <li class="px-5 py-3 hover:bg-zinc-50 transition-colors">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-zinc-800 truncate">{{ $pep['nombre'] ?? 'N/A' }}</p>
                                        <p class="text-[10px] text-zinc-500 truncate">{{ $pep['cargo'] ?? '' }} · {{ $pep['pais'] ?? '' }}</p>
                                        @if($pep['titulo'])
                                        <p class="text-[10px] text-zinc-400 truncate mt-0.5">{{ Str::limit($pep['titulo'], 55) }}</p>
                                        @endif
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <span class="simo-badge bg-emerald-50 text-emerald-600 text-[9px]">{{ $pep['confianza'] }}%</span>
                                        <p class="text-[10px] text-zinc-400 mt-0.5">{{ \Carbon\Carbon::parse($pep['fecha'])->format('d/m H:i') }}</p>
                                    </div>
                                </div>
                            </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="px-5 py-8 text-sm text-zinc-400 text-center">Sin datos suficientes</div>
                    @endif
                </div>

                {{-- Latest Corrections --}}
                <div class="simo-card p-0 overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100">
                        <span class="text-sm font-semibold text-zinc-800">Últimas correcciones</span>
                    </div>
                    @if(!empty($this->recentActivity->latestCorrections))
                        <ul class="divide-y divide-zinc-100">
                            @foreach($this->recentActivity->latestCorrections as $correction)
                            <li class="px-5 py-3 hover:bg-zinc-50 transition-colors">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-zinc-700 truncate">{{ $correction['usuario_nombre'] ?? 'Usuario' }}</p>
                                        <p class="text-[10px] text-zinc-500 truncate">{{ $correction['cargo'] ?? 'Sin cargo' }}</p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <span class="simo-badge {{ $correction['tipo'] === 'incorrecto' ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600' }} text-[9px]">
                                            {{ $correction['tipo'] }}
                                        </span>
                                        <p class="text-[10px] text-zinc-400 mt-0.5">{{ \Carbon\Carbon::parse($correction['fecha'])->format('d/m H:i') }}</p>
                                    </div>
                                </div>
                            </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="px-5 py-8 text-sm text-zinc-400 text-center">Sin datos suficientes</div>
                    @endif
                </div>
            </div>

        @endif
    </div>
    @endcan

</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function volumeChart(data) {
    return {
        chart: null,
        init() {
            this.render(data);
        },
        render(data) {
            if (this.chart) {
                this.chart.destroy();
            }
            const ctx = this.$refs.canvas.getContext('2d');
            const labels = data.map(d => d.month);
            const pepsData = data.map(d => d.peps);
            const opisData = data.map(d => d.opis);
            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'PEPs',
                            data: pepsData,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,0.1)',
                            tension: 0.3,
                            fill: true,
                        },
                        {
                            label: 'OPIs',
                            data: opisData,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245,158,11,0.08)',
                            tension: 0.3,
                            fill: true,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    },
                },
            });
        },
    };
}
</script>
@endpush
