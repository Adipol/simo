@props(['label', 'count', 'sparkline' => [], 'color' => 'zinc', 'href' => null])

@php
    $numberClass = match($color) {
        'rose'   => 'text-rose-600',
        'amber'  => 'text-amber-500',
        'emerald'=> 'text-emerald-600',
        'indigo' => 'text-indigo-600',
        default  => 'text-zinc-700',
    };
@endphp

@if($href)
    <a
        href="{{ $href }}"
        class="simo-card flex flex-col gap-1 hover:border-zinc-300 hover:shadow-sm transition-all group cursor-pointer"
        wire:navigate
    >
@else
    <div class="simo-card flex flex-col gap-1">
@endif

        <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">{{ $label }}</span>

        <span class="text-3xl font-bold {{ $numberClass }} tabular-nums leading-none">
            {{ number_format($count) }}
        </span>

        @if(!empty($sparkline))
            <div class="mt-1 h-6">
                <x-simo-sparkline :data="$sparkline" :color="$color" />
            </div>
        @endif

@if($href)
    </a>
@else
    </div>
@endif
