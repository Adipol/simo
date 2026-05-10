@props(['health', 'sourceHealth'])

{{-- @var \App\Services\Dashboard\DTOs\PipelineHealthDTO $health --}}
{{-- @var \App\Services\Dashboard\DTOs\SourceHealthSummaryDTO $sourceHealth --}}

<div class="flex flex-wrap items-center gap-2">

    {{-- Scraper --}}
    <x-dashboard.health-pill
        label="Scraper"
        :status="$health->scraper->status"
        :value="$health->scraper->ageFormatted()"
    />

    {{-- PEP Monitor --}}
    <x-dashboard.health-pill
        label="PEP Monitor"
        :status="$health->pep_monitor->status"
        :value="$health->pep_monitor->ageFormatted()"
    />

    {{-- Cola Gemini --}}
    @if($health->can_see_details)
        {{-- id="queue-depth-detail" marks this section for permission-gating tests --}}
        <span id="queue-depth-detail">
            <x-dashboard.health-pill
                label="Cola Gemini"
                :status="$health->queues->status"
                :value="$health->queues->total_pending . ' en cola'"
            />
        </span>
    @else
        <x-dashboard.health-pill
            label="Cola Gemini"
            :status="$health->queues->status"
        />
    @endif

    {{-- Fuentes --}}
    @if($sourceHealth->pillText() !== null)
        <x-dashboard.health-pill
            label="Fuentes"
            :status="$sourceHealth->pillStatus()"
            :value="$sourceHealth->pillText()"
        />
    @elseif($sourceHealth->isWarmup())
        <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-zinc-200 rounded-full text-xs">
            <span class="w-2 h-2 rounded-full shrink-0 bg-zinc-300 animate-pulse"></span>
            <span class="font-medium text-zinc-700">Fuentes</span>
            <span class="text-zinc-400 italic">Recolectando datos…</span>
        </div>
    @else
        <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-zinc-200 rounded-full text-xs">
            <span class="w-2 h-2 rounded-full shrink-0 bg-zinc-300"></span>
            <span class="font-medium text-zinc-700">Fuentes</span>
            <span class="text-zinc-400">Sin fuentes activas</span>
        </div>
    @endif

    {{-- Latencia --}}
    @if($health->latency->available)
        <x-dashboard.health-pill
            label="Latencia"
            status="ok"
            :value="'P50: ' . number_format($health->latency->p50_seconds ?? 0, 1) . 's'"
        />
    @else
        <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-zinc-200 rounded-full text-xs">
            <span class="w-2 h-2 rounded-full shrink-0 bg-zinc-300 animate-pulse"></span>
            <span class="font-medium text-zinc-400">Latencia</span>
            <span class="text-zinc-400 italic">Recolectando datos…</span>
        </div>
    @endif

    {{-- Cuota Gemini --}}
    @if($health->quota->available)
        <x-dashboard.health-pill
            label="Cuota Gemini"
            status="ok"
            :value="number_format($health->quota->tokens_today ?? 0) . ' tokens'"
        />
    @else
        <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-zinc-200 rounded-full text-xs">
            <span class="w-2 h-2 rounded-full shrink-0 bg-zinc-300 animate-pulse"></span>
            <span class="font-medium text-zinc-400">Cuota Gemini</span>
            <span class="text-zinc-400 italic">Recolectando datos…</span>
        </div>
    @endif

</div>
