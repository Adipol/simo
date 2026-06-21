@props(['estado'])

@php
    $classes = match ($estado) {
        'completado'   => 'bg-green-50 text-green-700',
        'error'        => 'bg-red-50 text-red-700',
        'interrumpido' => 'bg-orange-50 text-orange-600',
        default        => 'bg-amber-50 text-amber-700',
    };
@endphp

<span {{ $attributes->merge(['class' => 'simo-badge ' . $classes]) }}>
    {{ ucfirst($estado) }}
</span>
