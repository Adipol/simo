@props([
    'prefix',                               // 'scraper' | 'pep' | 'gaceta'
    'color',                                // 'indigo' | 'purple' | 'amber'
    'label',                                // display name
    'habilitado'     => true,              // current value (for display toggle label)
    'intervalo'      => 60,                // current value (for display)
    'timeout'        => 30,                // current value (for display)
    'intervalMin'    => 15,
    'intervalMax'    => 480,
    'intervalStep'   => 15,
    'intervalMarks'  => '15 min,1h,2h,4h,8h',
])

@php
    $habProp     = $prefix . 'Habilitado';
    $invProp     = $prefix . 'Intervalo';
    $hInicioProp = $prefix . 'HoraInicio';
    $hFinProp    = $prefix . 'HoraFin';
    $diasProp    = $prefix . 'Dias';
    $timeoutProp = $prefix . 'Timeout';
    $notasProp   = $prefix . 'Notas';
    $marks       = array_map('trim', explode(',', $intervalMarks));
    $dias_labels = [1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie', 6 => 'Sab', 7 => 'Dom'];
    $colorMap    = [
        'indigo' => ['bg' => 'bg-indigo-50', 'icon' => 'text-indigo-600', 'toggle' => 'peer-checked:bg-indigo-500', 'accent' => 'accent-indigo-600', 'label' => 'text-indigo-600', 'ring' => 'focus:ring-indigo-500/30 focus:border-indigo-400', 'day' => 'peer-checked:bg-indigo-600 peer-checked:border-indigo-600 hover:border-indigo-300'],
        'purple' => ['bg' => 'bg-purple-50', 'icon' => 'text-purple-600', 'toggle' => 'peer-checked:bg-purple-500', 'accent' => 'accent-purple-600', 'label' => 'text-purple-600', 'ring' => 'focus:ring-purple-500/30 focus:border-purple-400', 'day' => 'peer-checked:bg-purple-600 peer-checked:border-purple-600 hover:border-purple-300'],
        'amber'  => ['bg' => 'bg-amber-50',  'icon' => 'text-amber-600',  'toggle' => 'peer-checked:bg-amber-500',  'accent' => 'accent-amber-600',  'label' => 'text-amber-600',  'ring' => 'focus:ring-amber-500/30 focus:border-amber-400',  'day' => 'peer-checked:bg-amber-600 peer-checked:border-amber-600 hover:border-amber-300'],
    ];
    $c = $colorMap[$color] ?? $colorMap['indigo'];
@endphp

<div class="simo-card space-y-5">
    {{-- Titulo + toggle --}}
    <div class="flex items-center justify-between pb-4 border-b border-gray-100">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg {{ $c['bg'] }} flex items-center justify-center">
                {{ $slot }}
            </div>
            <h2 class="font-semibold text-gray-800">{{ $label }}</h2>
        </div>
        <label class="flex items-center gap-2 cursor-pointer select-none">
            <span class="text-xs text-gray-500">{{ $habilitado ? 'Habilitado' : 'Deshabilitado' }}</span>
            <div class="relative">
                <input type="checkbox" wire:model.live="{{ $habProp }}" class="sr-only peer">
                <div class="w-10 h-6 bg-gray-200 {{ $c['toggle'] }} rounded-full transition"></div>
                <div class="absolute top-1 left-1 w-4 h-4 bg-white rounded-full shadow transition peer-checked:translate-x-4"></div>
            </div>
        </label>
    </div>

    {{-- Intervalo --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <label class="text-sm font-medium text-gray-700">Intervalo de ejecucion</label>
            <span class="text-sm font-semibold {{ $c['label'] }}">
                @if($intervalo < 60)
                    {{ $intervalo }} min
                @else
                    {{ intdiv($intervalo, 60) }}h{{ ($intervalo % 60) > 0 ? ' '.($intervalo % 60).'min' : '' }}
                @endif
            </span>
        </div>
        <input type="range" wire:model.live="{{ $invProp }}"
            min="{{ $intervalMin }}" max="{{ $intervalMax }}" step="{{ $intervalStep }}"
            class="w-full {{ $c['accent'] }}">
        <div class="flex justify-between text-xs text-gray-400 mt-1">
            @foreach($marks as $mark)
                <span>{{ $mark }}</span>
            @endforeach
        </div>
        <div class="flex items-center gap-2 mt-2">
            <input type="number" wire:model.live="{{ $invProp }}"
                min="5" max="1440"
                class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 {{ $c['ring'] }}">
            <span class="text-xs text-gray-400">minutos</span>
        </div>
        @error($invProp) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Horario --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Ventana horaria <span class="text-gray-400 font-normal">(opcional)</span></label>
        <div class="flex items-center gap-3">
            <div>
                <label class="text-xs text-gray-400 mb-1 block">Desde</label>
                <input type="time" wire:model="{{ $hInicioProp }}"
                    class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 {{ $c['ring'] }}">
            </div>
            <div class="text-gray-300 mt-5">—</div>
            <div>
                <label class="text-xs text-gray-400 mb-1 block">Hasta</label>
                <input type="time" wire:model="{{ $hFinProp }}"
                    class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 {{ $c['ring'] }}">
            </div>
            <button type="button" wire:click="$set('{{ $hInicioProp }}', '')"
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
            @foreach($dias_labels as $num => $dayLabel)
                <label wire:key="dia-{{ $prefix }}-{{ $num }}" class="flex flex-col items-center gap-1 cursor-pointer">
                    <input type="checkbox" wire:model.live="{{ $diasProp }}" value="{{ $num }}" class="sr-only peer">
                    <div class="w-10 h-10 flex items-center justify-center rounded-lg border-2 text-xs font-semibold transition
                        {{ $c['day'] }}
                        border-gray-200 text-gray-500">
                        {{ $dayLabel }}
                    </div>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Timeout --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <label class="text-sm font-medium text-gray-700">Timeout maximo</label>
            <span class="text-sm font-semibold {{ $c['label'] }}">{{ $timeout }} min</span>
        </div>
        <input type="range" wire:model.live="{{ $timeoutProp }}"
            min="15" max="480" step="15"
            class="w-full {{ $c['accent'] }}">
        <p class="text-xs text-gray-400 mt-1.5">El runner cancela el proceso si supera este tiempo.</p>
        @error($timeoutProp) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Notas --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
        <textarea wire:model="{{ $notasProp }}" rows="2"
            class="simo-input resize-none"
            placeholder="Notas internas..."></textarea>
    </div>
</div>
