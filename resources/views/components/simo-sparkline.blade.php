@props(['data' => [], 'color' => 'rose', 'fill' => true])

@php
    $count  = count($data);
    $max    = $count > 0 ? max(max($data), 1) : 1;
    $width  = 80;
    $height = 24;

    $points = collect($data)->map(function ($v, $i) use ($count, $max, $width, $height) {
        $x = $count > 1 ? ($i / ($count - 1)) * $width : 0;
        $y = $height - ($v / $max) * $height;
        return $x . ',' . $y;
    })->join(' ');

    $colorMap = [
        'rose'    => ['stroke' => 'stroke-rose-500',    'fill' => 'fill-rose-500/10'],
        'amber'   => ['stroke' => 'stroke-amber-500',   'fill' => 'fill-amber-500/10'],
        'emerald' => ['stroke' => 'stroke-emerald-500', 'fill' => 'fill-emerald-500/10'],
        'indigo'  => ['stroke' => 'stroke-indigo-500',  'fill' => 'fill-indigo-500/10'],
        'default' => ['stroke' => 'stroke-zinc-400',    'fill' => 'fill-zinc-400/10'],
    ];

    $colorClass = $colorMap[$color] ?? $colorMap['default'];
@endphp

<svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full h-6" preserveAspectRatio="none" aria-hidden="true">
    @if($fill && $count > 0)
        <polygon
            points="0,{{ $height }} {{ $points }} {{ $width }},{{ $height }}"
            class="{{ $colorClass['fill'] }}"
        />
    @endif
    @if($count > 0)
        <polyline
            points="{{ $points }}"
            fill="none"
            class="{{ $colorClass['stroke'] }}"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    @endif
</svg>
