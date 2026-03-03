<div>
    <h1 class="text-2xl font-bold text-gray-800 mb-4">Estado de Scripts</h1>

    {{-- Tarjetas de estado actual --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">

        {{-- Scraper --}}
        <div class="bg-white rounded-lg shadow p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold text-gray-800">Scraper</h2>
                @if($scraperEjecutando)
                    <span class="inline-flex items-center gap-1.5 text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full font-medium">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        Ejecutando ahora
                    </span>
                @else
                    <span class="text-sm bg-gray-100 text-gray-500 px-3 py-1 rounded-full">Inactivo</span>
                @endif
            </div>
            @if($scraperUltimo)
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Ultima ejecucion</dt>
                        <dd class="font-medium text-gray-800">{{ $scraperUltimo->inicio->format('d/m/Y H:i') }}</dd>
                        <dd class="text-xs text-gray-400">{{ $scraperUltimo->inicio->diffForHumans() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Estado</dt>
                        <dd class="font-medium {{ $scraperUltimo->estado === 'error' ? 'text-red-600' : ($scraperUltimo->estado === 'completado' ? 'text-green-600' : 'text-amber-600') }}">
                            {{ ucfirst($scraperUltimo->estado) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Sitios procesados</dt>
                        <dd class="font-medium text-gray-800">{{ number_format($scraperUltimo->items_procesados) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Resultados encontrados</dt>
                        <dd class="font-medium text-gray-800">{{ number_format($scraperUltimo->items_resultado) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Errores</dt>
                        <dd class="font-medium {{ $scraperUltimo->errores > 0 ? 'text-red-600' : 'text-gray-800' }}">
                            {{ $scraperUltimo->errores }}
                        </dd>
                    </div>
                    @if($scraperUltimo->duracion_segundos)
                    <div>
                        <dt class="text-xs text-gray-500">Duracion</dt>
                        <dd class="font-medium text-gray-800">{{ round($scraperUltimo->duracion_segundos, 1) }}s</dd>
                    </div>
                    @endif
                </dl>
                @if($scraperUltimo->mensaje_error)
                    <div class="mt-3 bg-red-50 text-red-700 text-xs p-2 rounded">{{ $scraperUltimo->mensaje_error }}</div>
                @endif
            @else
                <p class="text-sm text-gray-400">Sin registros aun.</p>
            @endif
        </div>

        {{-- PEP Monitor --}}
        <div class="bg-white rounded-lg shadow p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold text-gray-800">PEP Monitor</h2>
                @if($pepEjecutando)
                    <span class="inline-flex items-center gap-1.5 text-sm bg-green-100 text-green-700 px-3 py-1 rounded-full font-medium">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        Ejecutando ahora
                    </span>
                @else
                    <span class="text-sm bg-gray-100 text-gray-500 px-3 py-1 rounded-full">Inactivo</span>
                @endif
            </div>
            @if($pepUltimo)
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500">Ultima ejecucion</dt>
                        <dd class="font-medium text-gray-800">{{ $pepUltimo->inicio->format('d/m/Y H:i') }}</dd>
                        <dd class="text-xs text-gray-400">{{ $pepUltimo->inicio->diffForHumans() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Estado</dt>
                        <dd class="font-medium {{ $pepUltimo->estado === 'error' ? 'text-red-600' : ($pepUltimo->estado === 'completado' ? 'text-green-600' : 'text-amber-600') }}">
                            {{ ucfirst($pepUltimo->estado) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Fuentes procesadas</dt>
                        <dd class="font-medium text-gray-800">{{ number_format($pepUltimo->items_procesados) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Cambios encontrados</dt>
                        <dd class="font-medium text-gray-800">{{ number_format($pepUltimo->items_resultado) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Errores</dt>
                        <dd class="font-medium {{ $pepUltimo->errores > 0 ? 'text-red-600' : 'text-gray-800' }}">
                            {{ $pepUltimo->errores }}
                        </dd>
                    </div>
                    @if($pepUltimo->duracion_segundos)
                    <div>
                        <dt class="text-xs text-gray-500">Duracion</dt>
                        <dd class="font-medium text-gray-800">{{ round($pepUltimo->duracion_segundos, 1) }}s</dd>
                    </div>
                    @endif
                </dl>
                @if($pepUltimo->mensaje_error)
                    <div class="mt-3 bg-red-50 text-red-700 text-xs p-2 rounded">{{ $pepUltimo->mensaje_error }}</div>
                @endif
            @else
                <p class="text-sm text-gray-400">Sin registros aun.</p>
            @endif
        </div>
    </div>

    {{-- Historial --}}
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b flex items-center justify-between">
            <h2 class="font-semibold text-gray-700">Historial de ejecuciones</h2>
            <div class="flex gap-2">
                <select wire:model.live="filtroScript" class="border rounded px-2 py-1 text-xs">
                    <option value="">Todos</option>
                    <option value="scraper">Scraper</option>
                    <option value="pep_monitor">PEP Monitor</option>
                </select>
                <select wire:model.live="filtroEstado" class="border rounded px-2 py-1 text-xs">
                    <option value="">Todos los estados</option>
                    <option value="iniciado">Iniciado</option>
                    <option value="completado">Completado</option>
                    <option value="error">Error</option>
                </select>
            </div>
        </div>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Script</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Inicio</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fin</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Procesados</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Resultados</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Errores</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Duracion</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($logs as $log)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2">
                            <span class="text-xs font-medium {{ $log->script === 'scraper' ? 'text-blue-600' : 'text-purple-600' }}">
                                {{ $log->script === 'scraper' ? 'Scraper' : 'PEP' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600">{{ $log->inicio->format('d/m/y H:i:s') }}</td>
                        <td class="px-3 py-2 text-xs text-gray-600">{{ $log->fin ? $log->fin->format('d/m/y H:i:s') : '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="text-xs px-1.5 py-0.5 rounded font-medium
                                {{ $log->estado === 'completado' ? 'bg-green-100 text-green-700' :
                                   ($log->estado === 'error' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">
                                {{ $log->estado }}
                            </span>
                            @if($log->mensaje_error)
                                <div class="text-xs text-red-500 mt-0.5 max-w-xs truncate" title="{{ $log->mensaje_error }}">
                                    {{ Str::limit($log->mensaje_error, 50) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">{{ $log->items_procesados }}</td>
                        <td class="px-3 py-2 text-xs text-center text-gray-600">{{ $log->items_resultado }}</td>
                        <td class="px-3 py-2 text-xs text-center {{ $log->errores > 0 ? 'text-red-600 font-medium' : 'text-gray-400' }}">
                            {{ $log->errores }}
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-500">
                            {{ $log->duracion_segundos ? round($log->duracion_segundos, 1).'s' : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-gray-400">Sin registros.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $logs->links() }}</div>
    </div>
</div>
