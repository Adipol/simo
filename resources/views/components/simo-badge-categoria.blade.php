@props(['categoria' => null])

@if($categoria)
    @php
        $classes = match($categoria) {
            'PEP' => 'bg-indigo-50 text-indigo-600',
            'OPI' => 'bg-amber-50 text-amber-600',
            default => 'bg-zinc-100 text-zinc-500 border-zinc-200',
        };
    @endphp
    <span {{ $attributes->merge(['class' => "simo-badge text-[9px] {$classes}"]) }}>
        {{ $categoria }}
    </span>
@endif
