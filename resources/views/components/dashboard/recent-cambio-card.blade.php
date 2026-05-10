@props(['cambio'])

@php
    /** @var \App\Services\Dashboard\DTOs\CambioSummary $cambio */
@endphp

<div class="flex items-start gap-3 px-5 py-3 hover:bg-zinc-50 transition-colors">

    <div class="min-w-0 flex-1">

        {{-- Source + risk badge --}}
        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-zinc-900 truncate">{{ $cambio->fuente_nombre }}</span>
            <span class="simo-badge {{ $cambio->riskBadgeClass() }} text-[9px] shrink-0">{{ strtoupper($cambio->riesgo) }}</span>
        </div>

        {{-- Snippet --}}
        @if($cambio->analisis_snippet)
            <p class="text-[10px] text-zinc-500 mt-0.5 line-clamp-2">{{ $cambio->analisis_snippet }}</p>
        @endif

        {{-- Stats row --}}
        <div class="flex items-center gap-2 mt-1">
            <span class="text-[10px] font-medium text-emerald-600">+{{ $cambio->lineas_nuevas }}</span>
            <span class="text-[10px] font-medium text-rose-500">-{{ $cambio->lineas_quitadas }}</span>
            <span class="text-[10px] text-zinc-400">·</span>
            <a
                href="{{ route('pep.cambios', ['id' => $cambio->id]) }}"
                class="text-[10px] font-medium text-indigo-600 hover:text-indigo-800 transition-colors"
                wire:navigate
            >
                Revisar
            </a>
        </div>

    </div>

    <span class="text-[10px] text-zinc-400 shrink-0 mt-0.5">{{ $cambio->diffForHumans() }}</span>

</div>
