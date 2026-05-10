<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Dashboard\DashboardHealthService;
use App\Services\Dashboard\DashboardSummaryService;
use App\Services\Dashboard\DTOs\DashboardSummaryDTO;
use App\Services\Dashboard\DTOs\PipelineHealthDTO;
use App\Services\Dashboard\DTOs\VolumeMetricsDTO;
use App\Services\Dashboard\DTOs\PrecisionMetricsDTO;
use App\Services\Dashboard\DTOs\GeographicMetricsDTO;
use App\Services\Dashboard\DTOs\RecentActivityDTO;
use App\Services\Dashboard\DTOs\TrendIndicatorsDTO;
use App\Services\Dashboard\DashboardMetricsService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    // ─── Statistics section state ─────────────────────────────────────────

    public bool $mostrarEstadisticas = false;

    #[Url]
    public ?string $filtroDateRange = null;

    #[Url]
    public ?string $filtroPais = null;

    #[Url]
    public ?string $filtroCategoria = null;

    // ─── Injected services ────────────────────────────────────────────────

    private DashboardSummaryService $summaryService;

    private DashboardHealthService $healthService;

    private DashboardMetricsService $metricsService;

    public function boot(
        DashboardSummaryService $summaryService,
        DashboardHealthService $healthService,
        DashboardMetricsService $metricsService,
    ): void {
        $this->summaryService = $summaryService;
        $this->healthService  = $healthService;
        $this->metricsService = $metricsService;
    }

    // ─── Toggle method ────────────────────────────────────────────────────

    public function toggleEstadisticas(): void
    {
        $this->authorize('ver dashboard estadisticas');

        $this->mostrarEstadisticas = ! $this->mostrarEstadisticas;
    }

    // ─── marcarRevisado action ────────────────────────────────────────────

    /**
     * Mark a cambio as reviewed, bust the summary cache, and refresh
     * the action island so the hero card updates immediately.
     *
     * Domain update is delegated to DashboardSummaryService::marcarRevisado()
     * which also handles the cache bust internally.
     */
    public function marcarRevisado(int $cambioId): void
    {
        $this->authorize('marcar revisado pep');

        $this->summaryService->marcarRevisado($cambioId);
    }

    // ─── Computed: dashboard summary (action + triage + discovery) ────────

    #[Computed(cache: true)]
    public function summary(): DashboardSummaryDTO
    {
        return $this->summaryService->getSnapshot();
    }

    // ─── Computed: pipeline health ────────────────────────────────────────

    #[Computed(cache: true)]
    public function health(): PipelineHealthDTO
    {
        /** @var ?User $user */
        $user = auth()->user();

        return $this->healthService->getHealth($user instanceof User ? $user : null);
    }

    // ─── Computed: lazy loaded metrics (stats section) ───────────────────

    #[Computed]
    public function volumeMetrics(): VolumeMetricsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return VolumeMetricsDTO::empty();
        }

        return $this->metricsService->getVolumeMetrics($this->buildFilters());
    }

    #[Computed]
    public function precisionMetrics(): PrecisionMetricsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return PrecisionMetricsDTO::empty();
        }

        return $this->metricsService->getPrecisionMetrics($this->buildFilters());
    }

    #[Computed]
    public function geographicMetrics(): GeographicMetricsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return GeographicMetricsDTO::empty();
        }

        return $this->metricsService->getGeographicMetrics($this->buildFilters());
    }

    #[Computed]
    public function recentActivity(): RecentActivityDTO
    {
        if (! $this->mostrarEstadisticas) {
            return RecentActivityDTO::empty();
        }

        return $this->metricsService->getRecentActivity($this->buildFilters());
    }

    #[Computed]
    public function trendIndicators(): TrendIndicatorsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return TrendIndicatorsDTO::empty();
        }

        return $this->metricsService->getTrendIndicators($this->buildFilters());
    }

    #[Computed]
    public function topFailingPositions(): array
    {
        if (! $this->mostrarEstadisticas) {
            return [];
        }

        return $this->metricsService->getTopFailingPositions($this->buildFilters());
    }

    /**
     * Derives the heatmap counts from geographic metrics.
     * ISO country code → PEP detection count, ready for <x-dashboard.latam-heatmap>.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function heatmapCounts(): array
    {
        if (! $this->mostrarEstadisticas) {
            return [];
        }

        return collect($this->geographicMetrics->byCountry)
            ->pluck('peps_count', 'pais')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Pre-format the recent activity data for Blade rendering.
     * DashboardMetricsService returns raw arrays with Carbon fecha instances;
     * we format dates here so Blade templates require no date-parsing logic.
     *
     * @return array{highConfidencePeps: array<int, array<string, mixed>>, latestCorrections: array<int, array<string, mixed>>}
     */
    #[Computed]
    public function formattedRecentActivity(): array
    {
        $activity = $this->recentActivity;

        $peps = array_map(function (array $pep): array {
            $pep['fecha_formateada'] = \Carbon\Carbon::parse($pep['fecha'])->format('d/m H:i');

            return $pep;
        }, $activity->highConfidencePeps);

        $corrections = array_map(function (array $correction): array {
            $correction['fecha_formateada'] = \Carbon\Carbon::parse($correction['fecha'])->format('d/m H:i');

            return $correction;
        }, $activity->latestCorrections);

        return [
            'highConfidencePeps'  => $peps,
            'latestCorrections'   => $corrections,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function buildFilters(): array
    {
        return [
            'date_range' => $this->filtroDateRange,
            'pais' => $this->filtroPais,
            'categoria' => $this->filtroCategoria,
        ];
    }

    // ─── Render ──────────────────────────────────────────────────────────

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.dashboard');
    }
}
