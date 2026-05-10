@props([
    'mostrarEstadisticas',
    'volumeMetrics',
    'precisionMetrics',
    'geographicMetrics',
    'trendIndicators',
    'topFailingPositions',
    'heatmapCounts' => [],
    'formattedRecentActivity' => ['highConfidencePeps' => [], 'latestCorrections' => []],
])

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
                    <select wire:model.live="filtroDateRange" class="simo-select text-xs py-1.5">
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
                        class="simo-input text-xs py-1.5 w-32"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-[10px] text-zinc-400 uppercase tracking-wide font-medium">Categoría</label>
                    <select wire:model.live="filtroCategoria" class="simo-select text-xs py-1.5">
                        <option value="">Todas</option>
                        <option value="PEP">PEP</option>
                        <option value="OPI">OPI</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- KPI Cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

            <div class="simo-card flex flex-col gap-1">
                <span class="text-xs font-medium text-zinc-400">Total PEPs</span>
                @if($volumeMetrics->hasData)
                    <span class="text-2xl font-bold text-indigo-600">{{ number_format($volumeMetrics->totalPeps) }}</span>
                    <div class="mt-1 h-6">
                        <x-simo-sparkline
                            :data="collect($volumeMetrics->monthlyTrend)->pluck('peps')->takeLast(7)->values()->all()"
                            color="indigo"
                        />
                    </div>
                @else
                    <span class="text-2xl font-bold text-zinc-300">0</span>
                @endif
                <span class="text-[10px] text-zinc-400">Detectados</span>
            </div>

            <div class="simo-card flex flex-col gap-1">
                <span class="text-xs font-medium text-zinc-400">Total OPIs</span>
                @if($volumeMetrics->hasData)
                    <span class="text-2xl font-bold text-amber-500">{{ number_format($volumeMetrics->totalOpis) }}</span>
                    <div class="mt-1 h-6">
                        <x-simo-sparkline
                            :data="collect($volumeMetrics->monthlyTrend)->pluck('opis')->takeLast(7)->values()->all()"
                            color="amber"
                        />
                    </div>
                @else
                    <span class="text-2xl font-bold text-zinc-300">0</span>
                @endif
                <span class="text-[10px] text-zinc-400">Detectados</span>
            </div>

            <div class="simo-card flex flex-col gap-1">
                <span class="text-xs font-medium text-zinc-400">Precisión Gemini</span>
                @if($precisionMetrics->hasData)
                    <span class="text-2xl font-bold text-emerald-600">{{ $precisionMetrics->overallAccuracy }}%</span>
                @else
                    <span class="text-2xl font-bold text-zinc-300">N/A</span>
                    <span class="text-[10px] text-zinc-400 mt-1">
                        Necesitás correcciones para ver precisión Gemini. Empezá a corregir desde la bandeja.
                    </span>
                @endif
                <span class="text-[10px] text-zinc-400">Basado en feedback</span>
            </div>

            <div class="simo-card flex flex-col gap-1">
                <span class="text-xs font-medium text-zinc-400">Sin leer</span>
                @if($volumeMetrics->hasData)
                    <span class="text-2xl font-bold {{ $volumeMetrics->unreadCount > 0 ? 'text-amber-500' : 'text-zinc-300' }}">
                        {{ number_format($volumeMetrics->unreadCount) }}
                    </span>
                @else
                    <span class="text-2xl font-bold text-zinc-300">0</span>
                @endif
                <span class="text-[10px] text-zinc-400">Resultados</span>
            </div>
        </div>

        {{-- Trend Indicators --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            @foreach([
                ['key' => 'peps',     'label' => 'Tendencia PEPs',     'trend' => $trendIndicators->pepsTrend],
                ['key' => 'opis',     'label' => 'Tendencia OPIs',     'trend' => $trendIndicators->opisTrend],
                ['key' => 'feedback', 'label' => 'Tendencia Feedback', 'trend' => $trendIndicators->feedbackTrend],
            ] as $indicator)
            <div wire:key="trend-{{ $indicator['key'] }}" class="simo-card flex items-center gap-4">
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

        {{-- Volume Trend Chart --}}
        <div class="simo-card mb-6">
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-semibold text-zinc-800">Volumen mensual (últimos 12 meses)</span>
            </div>
            @if($volumeMetrics->hasData)
                <div
                    x-data="volumeChart(@js($volumeMetrics->monthlyTrend))"
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
                <div class="text-center text-zinc-400 py-12 text-sm">
                    Sin datos suficientes para graficar tendencia mensual
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

            {{-- Precision buckets --}}
            <div class="simo-card">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-semibold text-zinc-800">Precisión por confianza</span>
                </div>
                @if($precisionMetrics->hasData)
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
                                 @foreach($precisionMetrics->byBucket as $bucket)
                                 <tr wire:key="bucket-{{ $bucket['bucket'] }}">
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
                @if(!empty($topFailingPositions))
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
                                 @foreach($topFailingPositions as $pos)
                                 <tr wire:key="pos-{{ $pos['cargo'] }}">
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

        {{-- Geographic Distribution: upgraded to heatmap --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="simo-card">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-semibold text-zinc-800">Distribución geográfica</span>
                </div>
                {{-- heatmapCounts is a #[Computed] from the Dashboard Livewire component --}}
                <x-dashboard.latam-heatmap :counts="$heatmapCounts" />
            </div>

            <div class="simo-card overflow-x-auto">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-semibold text-zinc-800">Por país</span>
                </div>
                @if($geographicMetrics->hasData)
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
                            @foreach($geographicMetrics->byCountry as $country)
                            <tr wire:key="country-{{ $country['pais'] }}">
                                <td class="font-semibold text-zinc-700">{{ $country['pais'] }}</td>
                                <td class="text-right text-indigo-600 font-medium">{{ number_format($country['peps_count']) }}</td>
                                <td class="text-right text-amber-500 font-medium">{{ number_format($country['opis_count']) }}</td>
                                <td class="text-right text-zinc-600">{{ $country['avg_confianza'] }}%</td>
                                <td class="text-right {{ $country['error_rate'] > 20 ? 'text-rose-600' : 'text-zinc-500' }}">{{ $country['error_rate'] }}%</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="text-center text-zinc-400 py-8 text-sm">Sin datos suficientes</div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            {{-- Recent High-Confidence PEPs --}}
            <div class="simo-card p-0 overflow-hidden">
                <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100">
                    <span class="text-sm font-semibold text-zinc-800">PEPs alta confianza</span>
                    <span class="simo-badge bg-indigo-50 text-indigo-600">≥ 90%</span>
                </div>
                @if(!empty($formattedRecentActivity['highConfidencePeps']))
                    <ul class="divide-y divide-zinc-100">
                        @foreach($formattedRecentActivity['highConfidencePeps'] as $pep)
                        <li wire:key="hc-pep-{{ md5(($pep['nombre'] ?? '') . ($pep['fecha'] ?? '')) }}" class="px-5 py-3 hover:bg-zinc-50 transition-colors">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-zinc-800 truncate">{{ $pep['nombre'] ?? 'N/A' }}</p>
                                    <p class="text-[10px] text-zinc-500 truncate">{{ $pep['cargo'] ?? '' }} · {{ $pep['pais'] ?? '' }}</p>
                                    @if(!empty($pep['titulo']))
                                    <p class="text-[10px] text-zinc-400 truncate mt-0.5">{{ Str::limit($pep['titulo'], 55) }}</p>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right">
                                    <span class="simo-badge bg-emerald-50 text-emerald-600 text-[9px]">{{ $pep['confianza'] }}%</span>
                                    <p class="text-[10px] text-zinc-400 mt-0.5">{{ $pep['fecha_formateada'] }}</p>
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
                @if(!empty($formattedRecentActivity['latestCorrections']))
                    <ul class="divide-y divide-zinc-100">
                        @foreach($formattedRecentActivity['latestCorrections'] as $correction)
                        <li wire:key="correction-{{ md5(($correction['usuario_nombre'] ?? '') . ($correction['fecha'] ?? '')) }}" class="px-5 py-3 hover:bg-zinc-50 transition-colors">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-zinc-700 truncate">{{ $correction['usuario_nombre'] ?? 'Usuario' }}</p>
                                    <p class="text-[10px] text-zinc-500 truncate">{{ $correction['cargo'] ?? 'Sin cargo' }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <span class="simo-badge {{ $correction['tipo'] === 'incorrecto' ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600' }} text-[9px]">
                                        {{ $correction['tipo'] }}
                                    </span>
                                    <p class="text-[10px] text-zinc-400 mt-0.5">{{ $correction['fecha_formateada'] }}</p>
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
