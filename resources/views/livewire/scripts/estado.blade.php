{{-- wire:poll.10s en el div RAIZ para que Livewire re-renderice todo el componente --}}
<div wire:poll.10s>

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Estado de Scripts</h1>
            <p class="text-sm text-gray-400 mt-0.5">Monitoreo en tiempo real de las ejecuciones</p>
        </div>
        <div class="text-xs text-gray-400">Actualiza cada 10s</div>
    </div>

    {{-- Tarjetas de estado actual --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

        {{-- Scraper --}}
        <div class="simo-card">
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="font-semibold text-gray-800">Scraper Web</h2>
                        <p class="text-xs text-gray-400">Monitoreo de sitios</p>
                    </div>
                </div>
                @if($scraperEjecutando)
                    <span class="simo-badge bg-green-50 text-green-700">
                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                        Ejecutando
                    </span>
                @else
                    <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200">Inactivo</span>
                @endif
            </div>

            {{-- Barra de progreso con contador JS en tiempo real --}}
            @if($scraperEjecutando && $scraperInicioTs)
                <div class="mb-4 p-3 bg-blue-50 rounded-xl border border-blue-100"
                     x-data="{
                         inicio: {{ $scraperInicioTs }},
                         timeout: {{ $scraperTimeoutSeg }},
                         elapsed: 0,
                         pct: 0,
                         fmt(s) {
                             let m = Math.floor(s / 60);
                             let sec = s % 60;
                             return m + 'm ' + String(sec).padStart(2,'0') + 's';
                         },
                         init() {
                             this.tick();
                             setInterval(() => this.tick(), 1000);
                         },
                         tick() {
                             this.elapsed = Math.floor(Date.now() / 1000) - this.inicio;
                             this.pct = Math.min(99, Math.round(this.elapsed / this.timeout * 100));
                         }
                     }">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-xs font-medium text-blue-700">Ciclo en curso — PEP → OPI por país</span>
                        <span class="text-xs text-blue-500 font-mono tabular-nums" x-text="fmt(elapsed)">—</span>
                    </div>
                    <div class="w-full bg-blue-100 rounded-full h-1.5">
                        <div class="bg-blue-500 h-1.5 rounded-full transition-none"
                             :style="'width:' + pct + '%'"></div>
                    </div>
                    <div class="text-[10px] text-blue-400 mt-1" x-text="'~' + pct + '% del timeout máximo ({{ intdiv($scraperTimeoutSeg, 60) }} min)'"></div>
                </div>
            @endif

            @if($scraperUltimo)
                <dl class="grid grid-cols-2 gap-3">
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Ultima ejecucion</dt>
                        <dd class="text-sm font-medium text-zinc-800">{{ $scraperUltimo->inicio->format('d/m/Y H:i') }}</dd>
                        <dd class="text-xs text-zinc-400">{{ $scraperUltimo->inicio->diffForHumans() }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Estado</dt>
                        <dd><x-simo-log-estado-badge :estado="$scraperUltimo->estado" /></dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Sitios procesados</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ number_format($scraperUltimo->items_procesados) }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Resultados</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ number_format($scraperUltimo->items_resultado) }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Errores</dt>
                        <dd class="text-sm font-semibold {{ $scraperUltimo->errores > 0 ? 'text-red-600' : 'text-zinc-800' }}">
                            {{ $scraperUltimo->errores }}
                        </dd>
                    </div>
                    @if($scraperUltimo->duracion_segundos)
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Duracion</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ round($scraperUltimo->duracion_segundos, 1) }}s</dd>
                    </div>
                    @endif
                </dl>
                @if($scraperUltimo->mensaje_error)
                    <div class="mt-3 bg-red-50 border border-red-100 text-red-700 text-xs px-3 py-2 rounded-lg">
                        {{ $scraperUltimo->mensaje_error }}
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-400 text-center py-4">Sin registros aun.</p>
            @endif
        </div>

        {{-- PEP Monitor --}}
        <div class="simo-card">
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="font-semibold text-gray-800">PEP Monitor</h2>
                        <p class="text-xs text-gray-400">Seguimiento de fuentes</p>
                    </div>
                </div>
                @if($pepEjecutando)
                    <span class="simo-badge bg-green-50 text-green-700">
                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                        Ejecutando
                    </span>
                @else
                    <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200">Inactivo</span>
                @endif
            </div>

            @if($pepUltimo)
                <dl class="grid grid-cols-2 gap-3">
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Ultima ejecucion</dt>
                        <dd class="text-sm font-medium text-zinc-800">{{ $pepUltimo->inicio->format('d/m/Y H:i') }}</dd>
                        <dd class="text-xs text-zinc-400">{{ $pepUltimo->inicio->diffForHumans() }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Estado</dt>
                        <dd><x-simo-log-estado-badge :estado="$pepUltimo->estado" /></dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Fuentes procesadas</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ number_format($pepUltimo->items_procesados) }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Cambios encontrados</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ number_format($pepUltimo->items_resultado) }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Errores</dt>
                        <dd class="text-sm font-semibold {{ $pepUltimo->errores > 0 ? 'text-red-600' : 'text-zinc-800' }}">
                            {{ $pepUltimo->errores }}
                        </dd>
                    </div>
                    @if($pepUltimo->duracion_segundos)
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Duracion</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ round($pepUltimo->duracion_segundos, 1) }}s</dd>
                    </div>
                    @endif
                </dl>
                @if($pepUltimo->mensaje_error)
                    <div class="mt-3 bg-red-50 border border-red-100 text-red-700 text-xs px-3 py-2 rounded-lg">
                        {{ $pepUltimo->mensaje_error }}
                    </div>
                @endif
            @else
                <p class="text-sm text-zinc-400 text-center py-4">Sin registros aun.</p>
            @endif
        </div>

        {{-- Gaceta Oficial --}}
        <div class="simo-card">
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="font-semibold text-gray-800">Gaceta Oficial</h2>
                        <p class="text-xs text-gray-400">
                            @if($gacetaConfig)
                                Cada {{ $gacetaConfig->intervaloLabel() }}
                            @else
                                Collector de decretos
                            @endif
                        </p>
                    </div>
                </div>
                @if($gacetaEjecutando)
                    <span class="simo-badge bg-green-50 text-green-700">
                        <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
                        Ejecutando
                    </span>
                @else
                    <span class="simo-badge bg-zinc-100 text-zinc-500 border-zinc-200">Inactivo</span>
                @endif
            </div>

            @if($gacetaUltimo)
                <dl class="grid grid-cols-2 gap-3">
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Ultima ejecucion</dt>
                        <dd class="text-sm font-medium text-zinc-800">{{ $gacetaUltimo->inicio->format('d/m/Y H:i') }}</dd>
                        <dd class="text-xs text-zinc-400">{{ $gacetaUltimo->inicio->diffForHumans() }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Estado</dt>
                        <dd><x-simo-log-estado-badge :estado="$gacetaUltimo->estado" /></dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Normas procesadas</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ number_format($gacetaUltimo->items_procesados) }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Nuevas encontradas</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ number_format($gacetaUltimo->items_resultado) }}</dd>
                    </div>
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Errores</dt>
                        <dd class="text-sm font-semibold {{ $gacetaUltimo->errores > 0 ? 'text-red-600' : 'text-zinc-800' }}">
                            {{ $gacetaUltimo->errores }}
                        </dd>
                    </div>
                    @if($gacetaUltimo->duracion_segundos)
                    <div class="bg-zinc-50 border border-zinc-100 rounded-lg p-3">
                        <dt class="text-xs text-zinc-400 mb-1">Duracion</dt>
                        <dd class="text-sm font-semibold text-zinc-800">{{ round($gacetaUltimo->duracion_segundos, 1) }}s</dd>
                    </div>
                    @endif
                </dl>
                @if($gacetaUltimo->mensaje_error)
                    <div class="mt-3 bg-red-50 border border-red-100 text-red-700 text-xs px-3 py-2 rounded-lg">
                        {{ $gacetaUltimo->mensaje_error }}
                    </div>
                @endif
            @else
                <p class="text-sm text-zinc-400 text-center py-4">Sin registros aun.</p>
            @endif
        </div>
    </div>

    {{-- Historial --}}
    <div class="simo-card p-0 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-200">
            <h2 class="font-semibold text-zinc-800">Historial de ejecuciones</h2>
            <div class="flex gap-2">
                <select wire:model.live="filtroScript" class="simo-select text-xs py-1.5">
                    <option value="">Todos los scripts</option>
                    <option value="scraper">Scraper</option>
                    <option value="pep_monitor">PEP Monitor</option>
                    <option value="gaceta">Gaceta</option>
                    <option value="gaceta_backfill">Gaceta Backfill</option>
                </select>
                <select wire:model.live="filtroEstado" class="simo-select text-xs py-1.5">
                    <option value="">Todos los estados</option>
                    <option value="iniciado">Iniciado</option>
                    <option value="completado">Completado</option>
                    <option value="error">Error</option>
                    <option value="interrumpido">Interrumpido</option>
                </select>
            </div>
        </div>

        <table class="simo-table min-w-full">
            <thead>
                <tr>
                    <th>Script</th>
                    <th>Inicio</th>
                    <th>Fin</th>
                    <th>Estado</th>
                    <th class="text-center">Procesados</th>
                    <th class="text-center">Resultados</th>
                    <th class="text-center">Errores</th>
                    <th>Duracion</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr wire:key="log-{{ $log->id }}">
                        <td>
                            @php
                                [$badgeClass, $badgeLabel] = match($log->script) {
                                    'scraper'         => ['bg-blue-50 text-blue-700', 'Scraper'],
                                    'pep_monitor'     => ['bg-purple-50 text-purple-700', 'PEP'],
                                    'gaceta'          => ['bg-amber-50 text-amber-700', 'Gaceta'],
                                    'gaceta_backfill' => ['bg-orange-50 text-orange-700', 'Gaceta BF'],
                                    default           => ['bg-zinc-50 text-zinc-700', $log->script],
                                };
                            @endphp
                            <span class="simo-badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                        </td>
                        <td class="text-xs text-zinc-500">{{ $log->inicio->format('d/m/y H:i:s') }}</td>
                        <td class="text-xs text-zinc-500">{{ $log->fin ? $log->fin->format('d/m/y H:i:s') : '—' }}</td>
                        <td>
                            <x-simo-log-estado-badge :estado="$log->estado" />
                            @if($log->mensaje_error)
                                <div class="text-xs text-red-400 mt-0.5 max-w-[180px] truncate" title="{{ $log->mensaje_error }}">
                                    {{ Str::limit($log->mensaje_error, 40) }}
                                </div>
                            @endif
                        </td>
                        <td class="text-center text-xs text-zinc-600">{{ $log->items_procesados }}</td>
                        <td class="text-center text-xs text-zinc-600">{{ $log->items_resultado }}</td>
                        <td class="text-center text-xs {{ $log->errores > 0 ? 'text-red-600 font-medium' : 'text-zinc-400' }}">
                            {{ $log->errores }}
                        </td>
                        <td class="text-xs text-zinc-500">
                            {{ $log->duracion_segundos ? round($log->duracion_segundos, 1).'s' : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-10 text-center text-zinc-400">Sin registros.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-zinc-200">{{ $logs->links() }}</div>
    </div>
</div>
