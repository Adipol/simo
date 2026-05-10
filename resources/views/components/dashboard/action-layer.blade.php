@props(['summary'])

@php
    /** @var \App\Services\Dashboard\DTOs\DashboardSummaryDTO $summary */
@endphp

<div class="space-y-4">

    {{-- Hero card --}}
    <x-dashboard.hero-card :hero="$summary->hero" />

    {{-- Triage strip --}}
    <x-dashboard.triage-strip :triage="$summary->triage" />

    {{-- Backlog age alert (shown when there are old pending items) --}}
    @if($summary->backlog->pendientes_antiguos > 0)
        <div class="flex items-center gap-2 px-4 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
            <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 110 18A9 9 0 0112 3z"/>
            </svg>
            <span>
                <strong>{{ $summary->backlog->pendientes_antiguos }}</strong>
                {{ $summary->backlog->pendientes_antiguos === 1 ? 'cambio tiene' : 'cambios tienen' }}
                más de {{ $summary->backlog->dias_threshold }} días sin revisar.
                @if($summary->backlog->mas_antiguo_dias !== null)
                    El más antiguo tiene <strong>{{ $summary->backlog->mas_antiguo_dias }}</strong> días.
                @endif
            </span>
        </div>
    @endif

</div>
