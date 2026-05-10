@props(['label', 'status' => 'ok', 'value' => null])

@php
    $dotClass = match($status) {
        'ok'          => 'bg-emerald-500',
        'warning'     => 'bg-amber-400',
        'error'       => 'bg-rose-500',
        'no_data',
        'sin_registros' => 'bg-zinc-300',
        default       => 'bg-zinc-300',
    };

    $textClass = match($status) {
        'ok'          => 'text-emerald-700',
        'warning'     => 'text-amber-700',
        'error'       => 'text-rose-700',
        default       => 'text-zinc-500',
    };
@endphp

<div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-zinc-200 rounded-full text-xs">
    <span class="w-2 h-2 rounded-full shrink-0 {{ $dotClass }}"></span>
    <span class="font-medium text-zinc-700">{{ $label }}</span>
    @if($value !== null)
        <span class="{{ $textClass }}">{{ $value }}</span>
    @endif
</div>
