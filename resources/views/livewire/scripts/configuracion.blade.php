<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Configuracion de Scripts</h1>
            <p class="text-sm text-gray-400 mt-0.5">Programacion y parametros de ejecucion</p>
        </div>
        <button wire:click="guardar"
            class="simo-btn-primary text-sm px-4 py-2">
            Guardar cambios
        </button>
    </div>

    @if($mensaje)
        <div x-data="{ visible: true }"
             x-init="setTimeout(() => visible = false, 4000)"
             x-show="visible"
             x-transition:leave="transition duration-500"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="mb-5 flex items-center gap-2 px-4 py-3 rounded-lg text-sm font-medium border
                {{ $tipoMensaje === 'success'
                    ? 'bg-green-50 text-green-700 border-green-200'
                    : 'bg-red-50 text-red-700 border-red-200' }}">
            @if($tipoMensaje === 'success')
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            @else
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            @endif
            {{ $mensaje }}
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        {{-- ===== SCRAPER ===== --}}
        <x-scripts.config-card
            prefix="scraper"
            color="indigo"
            label="Scraper Web"
            :habilitado="$scraperHabilitado"
            :intervalo="$scraperIntervalo"
            :timeout="$scraperTimeout"
            :interval-min="15"
            :interval-max="480"
            :interval-step="15"
            interval-marks="15 min,1h,2h,4h,8h">
            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
            </svg>
        </x-scripts.config-card>

        {{-- ===== PEP MONITOR ===== --}}
        <x-scripts.config-card
            prefix="pep"
            color="purple"
            label="PEP Monitor"
            :habilitado="$pepHabilitado"
            :intervalo="$pepIntervalo"
            :timeout="$pepTimeout"
            :interval-min="15"
            :interval-max="1440"
            :interval-step="15"
            interval-marks="15 min,2h,6h,12h,24h">
            <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
        </x-scripts.config-card>

        {{-- ===== GACETA OFICIAL ===== --}}
        <x-scripts.config-card
            prefix="gaceta"
            color="amber"
            label="Gaceta Oficial"
            :habilitado="$gacetaHabilitado"
            :intervalo="$gacetaIntervalo"
            :timeout="$gacetaTimeout"
            :interval-min="15"
            :interval-max="480"
            :interval-step="15"
            interval-marks="15 min,1h,2h,4h,8h">
            <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
            </svg>
        </x-scripts.config-card>

    </div>

    {{-- Info del runner --}}
    <div class="mt-6 bg-indigo-50 border border-indigo-100 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-indigo-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="text-sm text-indigo-800">
                <p class="font-semibold mb-1">Como aplicar los cambios</p>
                <p>Los cambios se guardan en la base de datos. El <strong>runner</strong> lee esta configuracion en cada ciclo y la aplica automaticamente.</p>
                <p class="mt-1">Para iniciar el runner:
                    <code class="bg-indigo-100 px-1.5 py-0.5 rounded font-mono text-xs">{{ config('scripts.runner_command', 'python runner.py') }}</code>
                </p>
            </div>
        </div>
    </div>
</div>
