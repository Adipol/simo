@props(['discoveries'])

@php
    /** @var \App\Services\Dashboard\DTOs\RecentDiscoveriesDTO $discoveries */
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    {{-- Left column: high-confidence PEPs detected in last 24h --}}
    <div class="simo-card p-0 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100">
            <span class="text-sm font-semibold text-zinc-800">Personas detectadas (24h)</span>
            @if(!empty($discoveries->top_peps))
                <span class="simo-badge bg-indigo-50 text-indigo-600">{{ count($discoveries->top_peps) }}</span>
            @endif
        </div>

        @if(!empty($discoveries->top_peps))
            <ul class="divide-y divide-zinc-100">
                @foreach($discoveries->top_peps as $pep)
                    <li wire:key="pep-{{ $pep->id }}">
                        <x-dashboard.recent-pep-card :pep="$pep" />
                    </li>
                @endforeach
            </ul>
        @else
            <div class="px-5 py-8 text-center">
                <p class="text-sm text-zinc-400">Aún sin personas detectadas hoy</p>
                <p class="text-xs text-zinc-300 mt-1">Activa Gemini con feedback para ver detecciones de alta confianza</p>
            </div>
        @endif
    </div>

    {{-- Right column: recent PEP cambios --}}
    <div class="simo-card p-0 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100">
            <span class="text-sm font-semibold text-zinc-800">Cambios PEP recientes</span>
            @if(!empty($discoveries->top_cambios))
                <a href="{{ route('pep.cambios') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium" wire:navigate>
                    Ver todos
                </a>
            @endif
        </div>

        @if(!empty($discoveries->top_cambios))
            <ul class="divide-y divide-zinc-100">
                @foreach($discoveries->top_cambios as $cambio)
                    <li wire:key="cambio-{{ $cambio->id }}">
                        <x-dashboard.recent-cambio-card :cambio="$cambio" />
                    </li>
                @endforeach
            </ul>
        @else
            <div class="px-5 py-8 text-center">
                <p class="text-sm text-zinc-400">Sin cambios recientes con personas</p>
                <p class="text-xs text-zinc-300 mt-1">Los cambios con personas detectadas por Gemini aparecerán aquí</p>
            </div>
        @endif
    </div>

</div>
