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

    @php
        $dias_labels = [1=>'Lun', 2=>'Mar', 3=>'Mie', 4=>'Jue', 5=>'Vie', 6=>'Sab', 7=>'Dom'];
    @endphp

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        {{-- ===== SCRAPER ===== --}}
        <div class="simo-card space-y-5">
            {{-- Titulo + toggle --}}
            <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                        </svg>
                    </div>
                    <h2 class="font-semibold text-gray-800">Scraper Web</h2>
                </div>
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <span class="text-xs text-gray-500">{{ $scraperHabilitado ? 'Habilitado' : 'Deshabilitado' }}</span>
                    <div class="relative">
                        <input type="checkbox" wire:model.live="scraperHabilitado" class="sr-only peer">
                        <div class="w-10 h-6 bg-gray-200 peer-checked:bg-indigo-500 rounded-full transition"></div>
                        <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition peer-checked:translate-x-4"></div>
                    </div>
                </label>
            </div>

            {{-- Intervalo --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-700">Intervalo de ejecucion</label>
                    <span class="text-sm font-semibold text-indigo-600">
                        @if($scraperIntervalo < 60)
                            {{ $scraperIntervalo }} min
                        @else
                            {{ intdiv($scraperIntervalo, 60) }}h{{ ($scraperIntervalo % 60) > 0 ? ' '.($scraperIntervalo % 60).'min' : '' }}
                        @endif
                    </span>
                </div>
                <input type="range" wire:model.live="scraperIntervalo"
                    min="15" max="480" step="15"
                    class="w-full accent-indigo-600">
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                    <span>15 min</span><span>1h</span><span>2h</span><span>4h</span><span>8h</span>
                </div>
                <div class="flex items-center gap-2 mt-2">
                    <input type="number" wire:model.live="scraperIntervalo"
                        min="5" max="1440"
                        class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400">
                    <span class="text-xs text-gray-400">minutos</span>
                </div>
                @error('scraperIntervalo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Horario --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Ventana horaria <span class="text-gray-400 font-normal">(opcional)</span></label>
                <div class="flex items-center gap-3">
                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Desde</label>
                        <input type="time" wire:model="scraperHoraInicio"
                            class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400">
                    </div>
                    <div class="text-gray-300 mt-5">—</div>
                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Hasta</label>
                        <input type="time" wire:model="scraperHoraFin"
                            class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400">
                    </div>
                    <button type="button" wire:click="$set('scraperHoraInicio', '')"
                        class="mt-5 text-xs text-gray-400 hover:text-red-500 transition">
                        Quitar
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1.5">Dejar vacio para ejecutar a cualquier hora.</p>
            </div>

            {{-- Dias --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Dias activos</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach($dias_labels as $num => $label)
                        <label class="flex flex-col items-center gap-1 cursor-pointer">
                            <input type="checkbox" wire:model.live="scraperDias" value="{{ $num }}" class="sr-only peer">
                            <div class="w-10 h-10 flex items-center justify-center rounded-lg border-2 text-xs font-semibold transition
                                peer-checked:bg-indigo-600 peer-checked:border-indigo-600 peer-checked:text-white
                                border-gray-200 text-gray-500 hover:border-indigo-300">
                                {{ $label }}
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Timeout --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-700">Timeout maximo</label>
                    <span class="text-sm font-semibold text-indigo-600">{{ $scraperTimeout }} min</span>
                </div>
                <input type="range" wire:model.live="scraperTimeout"
                    min="15" max="480" step="15"
                    class="w-full accent-indigo-600">
                <p class="text-xs text-gray-400 mt-1.5">El runner cancela el proceso si supera este tiempo.</p>
                @error('scraperTimeout') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Notas --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                <textarea wire:model="scraperNotas" rows="2"
                    class="simo-input resize-none"
                    placeholder="Notas internas..."></textarea>
            </div>
        </div>

        {{-- ===== PEP MONITOR ===== --}}
        <div class="simo-card space-y-5">
            {{-- Titulo + toggle --}}
            <div class="flex items-center justify-between pb-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center">
                        <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <h2 class="font-semibold text-gray-800">PEP Monitor</h2>
                </div>
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <span class="text-xs text-gray-500">{{ $pepHabilitado ? 'Habilitado' : 'Deshabilitado' }}</span>
                    <div class="relative">
                        <input type="checkbox" wire:model.live="pepHabilitado" class="sr-only peer">
                        <div class="w-10 h-6 bg-gray-200 peer-checked:bg-purple-500 rounded-full transition"></div>
                        <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition peer-checked:translate-x-4"></div>
                    </div>
                </label>
            </div>

            {{-- Intervalo --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-700">Intervalo de ejecucion</label>
                    <span class="text-sm font-semibold text-purple-600">
                        @if($pepIntervalo < 60)
                            {{ $pepIntervalo }} min
                        @else
                            {{ intdiv($pepIntervalo, 60) }}h{{ ($pepIntervalo % 60) > 0 ? ' '.($pepIntervalo % 60).'min' : '' }}
                        @endif
                    </span>
                </div>
                <input type="range" wire:model.live="pepIntervalo"
                    min="15" max="1440" step="15"
                    class="w-full accent-purple-600">
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                    <span>15 min</span><span>2h</span><span>6h</span><span>12h</span><span>24h</span>
                </div>
                <div class="flex items-center gap-2 mt-2">
                    <input type="number" wire:model.live="pepIntervalo"
                        min="5" max="1440"
                        class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-purple-500/30 focus:border-purple-400">
                    <span class="text-xs text-gray-400">minutos</span>
                </div>
                @error('pepIntervalo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Horario --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Ventana horaria <span class="text-gray-400 font-normal">(opcional)</span></label>
                <div class="flex items-center gap-3">
                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Desde</label>
                        <input type="time" wire:model="pepHoraInicio"
                            class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500/30 focus:border-purple-400">
                    </div>
                    <div class="text-gray-300 mt-5">—</div>
                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Hasta</label>
                        <input type="time" wire:model="pepHoraFin"
                            class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500/30 focus:border-purple-400">
                    </div>
                    <button type="button" wire:click="$set('pepHoraInicio', '')"
                        class="mt-5 text-xs text-gray-400 hover:text-red-500 transition">
                        Quitar
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1.5">Dejar vacio para ejecutar a cualquier hora.</p>
            </div>

            {{-- Dias --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Dias activos</label>
                <div class="flex gap-2 flex-wrap">
                    @foreach($dias_labels as $num => $label)
                        <label class="flex flex-col items-center gap-1 cursor-pointer">
                            <input type="checkbox" wire:model.live="pepDias" value="{{ $num }}" class="sr-only peer">
                            <div class="w-10 h-10 flex items-center justify-center rounded-lg border-2 text-xs font-semibold transition
                                peer-checked:bg-purple-600 peer-checked:border-purple-600 peer-checked:text-white
                                border-gray-200 text-gray-500 hover:border-purple-300">
                                {{ $label }}
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Timeout --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-700">Timeout maximo</label>
                    <span class="text-sm font-semibold text-purple-600">{{ $pepTimeout }} min</span>
                </div>
                <input type="range" wire:model.live="pepTimeout"
                    min="15" max="480" step="15"
                    class="w-full accent-purple-600">
                <p class="text-xs text-gray-400 mt-1.5">El runner cancela el proceso si supera este tiempo.</p>
                @error('pepTimeout') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Notas --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                <textarea wire:model="pepNotas" rows="2"
                    class="simo-input resize-none"
                    placeholder="Notas internas..."></textarea>
            </div>
        </div>
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
                <p>Los cambios se guardan en la base de datos. El <strong>runner.py</strong> lee esta configuracion en cada ciclo y la aplica automaticamente.</p>
                <p class="mt-1">Para iniciar el runner:
                    <code class="bg-indigo-100 px-1.5 py-0.5 rounded font-mono text-xs">py runner.py</code>
                    desde
                    <code class="bg-indigo-100 px-1.5 py-0.5 rounded font-mono text-xs">D:\proyectos\website_monitor_pro</code>
                </p>
            </div>
        </div>
    </div>
</div>
