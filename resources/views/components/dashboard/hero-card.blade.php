@props(['hero'])

@php
    /** @var \App\Services\Dashboard\DTOs\HeroCardDTO|null $hero */
    $riskBorderClass = 'border-zinc-200';
    $riskBadgeClass  = 'bg-zinc-100 text-zinc-600';

    if ($hero !== null) {
        $riskBorderClass = match($hero->riesgo) {
            'alto'  => 'border-l-4 border-rose-500',
            'medio' => 'border-l-4 border-amber-400',
            default => 'border-zinc-200',
        };
        $riskBadgeClass = match($hero->riesgo) {
            'alto'  => 'bg-rose-50 text-rose-600',
            'medio' => 'bg-amber-50 text-amber-600',
            'bajo'  => 'bg-emerald-50 text-emerald-600',
            default => 'bg-zinc-100 text-zinc-600',
        };
    }
@endphp

@if($hero !== null)
    <div class="simo-card {{ $riskBorderClass }} flex flex-col gap-3">

        {{-- Header row: source + risk badge --}}
        <div class="flex items-center justify-between gap-3">
            <span class="text-sm font-bold text-zinc-900 truncate">{{ $hero->fuente_nombre }}</span>
            <div class="flex items-center gap-1.5 shrink-0">
                @if($hero->es_mae)
                    <span class="simo-badge bg-purple-50 text-purple-600 text-[9px]">MAE</span>
                @endif
                <span class="simo-badge {{ $riskBadgeClass }}">
                    {{ strtoupper($hero->riesgo) }}
                </span>
            </div>
        </div>

        {{-- Meta info --}}
        <div class="flex items-center gap-3 text-xs text-zinc-500">
            <span>Hace {{ $hero->dias_pendiente }} {{ $hero->dias_pendiente === 1 ? 'día' : 'días' }}</span>
            <span class="text-zinc-300">·</span>
            <span>Score: {{ number_format($hero->score, 1) }}</span>
        </div>

        {{-- Action button --}}
        <div class="mt-1">
            <a
                href="{{ $hero->accion_url }}"
                class="simo-btn simo-btn-primary text-sm"
                wire:navigate
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                Revisar ahora
            </a>
        </div>
    </div>

@else
    {{-- Celebratory empty state --}}
    <div class="simo-card flex flex-col items-center justify-center gap-3 py-8 text-center">
        <div class="w-12 h-12 bg-emerald-50 rounded-full flex items-center justify-center">
            <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <div>
            <p class="text-base font-bold text-zinc-800">Todo al día</p>
            <p class="text-xs text-zinc-500 mt-0.5">No hay cambios pendientes con personas detectadas</p>
        </div>
    </div>
@endif
