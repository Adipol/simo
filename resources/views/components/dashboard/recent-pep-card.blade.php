@props(['pep'])

@php
    /** @var \App\Services\Dashboard\DTOs\PepHighConfidence $pep */
@endphp

<div class="flex items-start gap-3 px-5 py-3 hover:bg-zinc-50 transition-colors">

    {{-- Avatar with initials and confidence-based color --}}
    <div class="w-8 h-8 rounded-full {{ $pep->avatarColorClass() }} flex items-center justify-center shrink-0">
        <span class="text-[10px] font-bold text-white">{{ $pep->initials() }}</span>
    </div>

    {{-- Content --}}
    <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold text-zinc-900 truncate">{{ $pep->nombre }}</p>
        <p class="text-[10px] text-zinc-500 truncate">
            {{ $pep->cargo ?? 'Cargo desconocido' }}
            @if($pep->pais) · {{ $pep->pais }} @endif
            @if($pep->categoria) · {{ $pep->categoria }} @endif
        </p>

        {{-- Confidence bar --}}
        <div class="mt-1.5">
            <div class="h-1 bg-zinc-100 rounded-full overflow-hidden">
                <div
                    class="h-full {{ $pep->confidenceBarColorClass() }} rounded-full transition-all"
                    style="width: {{ $pep->clampedConfianza() }}%"
                ></div>
            </div>
            <span class="text-[9px] text-zinc-400 mt-0.5 inline-block">{{ number_format($pep->confianza, 0) }}% confianza</span>
        </div>
    </div>

    {{-- Time --}}
    <span class="text-[10px] text-zinc-400 shrink-0 mt-0.5">{{ $pep->formattedTime() }}</span>

</div>
