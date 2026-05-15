{{--
    Precision Dashboard — /admin/precision
    4 Chart.js charts driven by DescartadosAnalisisService.

    Auto-refreshes every 300s (aligned with cache TTL — REQ-3).
    Chart canvases are wrapped in wire:ignore so Livewire polls do NOT
    destroy the canvas element; Alpine.js owns chart lifecycle instead.

    feedback-loop-from-descartados · PR-C · design §Blade view
--}}
<div wire:poll.300s class="space-y-6">

    {{-- ── PAGE HEADER ────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100">Análisis de Precisión</h1>
            <p class="text-sm text-zinc-400 mt-0.5">Retroalimentación implícita · últimos 30 días</p>
        </div>
        <button
            wire:click="refrescarAhora"
            wire:loading.attr="disabled"
            class="simo-btn simo-btn-secondary text-sm"
        >
            <span wire:loading.remove>Refrescar ahora</span>
            <span wire:loading>Actualizando…</span>
        </button>
    </div>

    {{-- ── PRECISION CARD (REQ-1 / SCN-5.1) ──────────────────────────── --}}
    <div data-precision-card class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
        @if ($this->metricsGenerales->precisionPct === null)
            {{-- Insufficient data path (REQ-5) --}}
            <p class="text-sm text-zinc-400">
                <span class="font-medium text-amber-400">datos insuficientes</span>
                — {{ $this->metricsGenerales->insufficientReason }}
            </p>
        @else
            <div class="flex items-baseline gap-3">
                <span class="text-4xl font-bold text-indigo-400">
                    {{ number_format($this->metricsGenerales->precisionPct, 1) }}%
                </span>
                <span class="text-sm text-zinc-400">precisión general (crece con uso)</span>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-4 text-sm text-zinc-400">
                <div>
                    <span class="block text-lg font-semibold text-zinc-200">
                        {{ number_format($this->metricsGenerales->totalProcesados) }}
                    </span>
                    procesados
                </div>
                <div>
                    <span class="block text-lg font-semibold text-zinc-200">
                        {{ number_format($this->metricsGenerales->totalDescartados) }}
                    </span>
                    descartados
                </div>
                <div>
                    <span class="block text-lg font-semibold text-zinc-200">
                        {{ number_format($this->metricsGenerales->totalRelevantes) }}
                    </span>
                    relevantes
                </div>
            </div>
        @endif
    </div>

    {{-- ── 2×2 CHART GRID ─────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

        {{-- ── Chart 1: Top Lemas Problemáticos (horizontal bar) ──────── --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <h2 class="mb-3 text-sm font-semibold text-zinc-300">Top Lemas Problemáticos</h2>
            <div
                x-data="precisionBarChart({{ Js::from($this->topLemasChart) }}, 'horizontal')"
                x-init="init()"
                wire:ignore
            >
                <canvas x-ref="canvas" data-chart="lemas" class="max-h-64 w-full"></canvas>
            </div>
        </div>

        {{-- ── Chart 2: Top Sitios Problemáticos (horizontal bar) ──────── --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <h2 class="mb-3 text-sm font-semibold text-zinc-300">Top Sitios Problemáticos</h2>
            <div
                x-data="precisionBarChart({{ Js::from($this->topSitiosChart) }}, 'horizontal')"
                x-init="init()"
                wire:ignore
            >
                <canvas x-ref="canvas" data-chart="sitios" class="max-h-64 w-full"></canvas>
            </div>
        </div>

        {{-- ── Chart 3: Confianza Gemini vs % Descartado (vertical bar) ── --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <h2 class="mb-3 text-sm font-semibold text-zinc-300">Confianza Gemini vs Descartado</h2>
            <div
                x-data="precisionBarChart({{ Js::from($this->confianzaChart) }}, 'vertical')"
                x-init="init()"
                wire:ignore
            >
                <canvas x-ref="canvas" data-chart="confianza" class="max-h-64 w-full"></canvas>
            </div>
        </div>

        {{-- ── Chart 4: Drift por Keyword (horizontal bar) ──────────────── --}}
        <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 p-5">
            <h2 class="mb-3 text-sm font-semibold text-zinc-300">Drift por Keyword (30d vs 60d)</h2>
            <div
                x-data="precisionBarChart({{ Js::from($this->driftChart) }}, 'horizontal')"
                x-init="init()"
                wire:ignore
            >
                <canvas x-ref="canvas" data-chart="drift" class="max-h-64 w-full"></canvas>
            </div>
        </div>

    </div>

</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
/**
 * Reusable Alpine.js component for precision bar charts.
 *
 * @param {Array<{label: string, value: number}>} items  Data points.
 * @param {'horizontal'|'vertical'} direction            Chart orientation.
 */
function precisionBarChart(items, direction) {
    return {
        chart: null,

        init() {
            if (typeof Chart === 'undefined') {
                return;
            }

            const labels = items.map(d => d.label);
            const values = items.map(d => d.value);
            const isHorizontal = direction === 'horizontal';

            if (this.chart) {
                this.chart.destroy();
            }

            const ctx = this.$refs.canvas.getContext('2d');
            this.chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: '% descartado',
                        data: values,
                        backgroundColor: 'rgba(99, 102, 241, 0.7)',
                        borderColor: '#6366f1',
                        borderWidth: 1,
                    }],
                },
                options: {
                    indexAxis: isHorizontal ? 'y' : 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ctx.parsed[isHorizontal ? 'x' : 'y'].toFixed(1) + '%',
                            },
                        },
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: isHorizontal ? 100 : undefined,
                            ticks: { color: '#a1a1aa' },
                            grid:  { color: '#3f3f46' },
                        },
                        y: {
                            beginAtZero: true,
                            max: isHorizontal ? undefined : 100,
                            ticks: { color: '#a1a1aa' },
                            grid:  { color: '#3f3f46' },
                        },
                    },
                },
            });
        },
    };
}
</script>
@endpush
