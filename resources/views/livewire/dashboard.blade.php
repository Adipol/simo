<div class="space-y-6">

    {{-- ── ACTION ISLAND (summary: hero card + triage strip + backlog alert) ── --}}
    {{-- Outer wrapper polls every 30s — summary cache TTL is 60s --}}
    <div wire:poll.30s>
        @island('action')
            <x-dashboard.action-layer :summary="$this->summary" />
        @endisland
    </div>

    {{-- ── HEALTH STRIP (pipeline health pills) ── --}}
    {{-- Polls every 15s — health cache TTL is 15s --}}
    <div wire:poll.15s>
        @island('health')
            <x-dashboard.health-strip :health="$this->health" />
        @endisland
    </div>

    {{-- ── DISCOVERY ISLAND (personas + cambios recientes) ── --}}
    {{-- Polls every 60s — discovery data changes infrequently --}}
    <div wire:poll.60s>
        @island('discovery')
            <x-dashboard.discovery-layer :discoveries="$this->summary->discoveries" />
        @endisland
    </div>

    {{-- ── ANALYTICS (admin/supervisor only, lazy) ── --}}
    {{-- No polling — only loads when user expands it --}}
    <x-dashboard.analytics-section
        :mostrarEstadisticas="$mostrarEstadisticas"
        :volumeMetrics="$this->volumeMetrics"
        :precisionMetrics="$this->precisionMetrics"
        :geographicMetrics="$this->geographicMetrics"
        :trendIndicators="$this->trendIndicators"
        :topFailingPositions="$this->topFailingPositions"
        :heatmapCounts="$this->heatmapCounts"
        :formattedRecentActivity="$this->formattedRecentActivity"
    />

</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function volumeChart(data) {
    return {
        chart: null,
        init() {
            this.render(data);
        },
        render(data) {
            if (this.chart) {
                this.chart.destroy();
            }
            const ctx = this.$refs.canvas.getContext('2d');
            const labels = data.map(d => d.month);
            const pepsData = data.map(d => d.peps);
            const opisData = data.map(d => d.opis);
            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'PEPs',
                            data: pepsData,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,0.1)',
                            tension: 0.3,
                            fill: true,
                        },
                        {
                            label: 'OPIs',
                            data: opisData,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245,158,11,0.08)',
                            tension: 0.3,
                            fill: true,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    },
                },
            });
        },
    };
}
</script>
@endpush
