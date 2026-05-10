<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\LogScript;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DTOs\GeographicMetricsDTO;
use App\Services\Dashboard\DTOs\PrecisionMetricsDTO;
use App\Services\Dashboard\DTOs\RecentActivityDTO;
use App\Services\Dashboard\DTOs\TrendIndicatorsDTO;
use App\Services\Dashboard\DTOs\VolumeMetricsDTO;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    // ─── Statistics section state ─────────────────────────────────────────

    public bool $mostrarEstadisticas = false;

    public ?string $filtroDateRange = null;

    public ?string $filtroPais = null;

    public ?string $filtroCategoria = null;

    // ─── Toggle method ────────────────────────────────────────────────────

    public function toggleEstadisticas(): void
    {
        $this->authorize('ver dashboard estadisticas');

        $this->mostrarEstadisticas = ! $this->mostrarEstadisticas;
    }

    // ─── Computed: lazy loaded metrics ───────────────────────────────────

    #[Computed]
    public function volumeMetrics(): VolumeMetricsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return VolumeMetricsDTO::empty();
        }

        return app(DashboardMetricsService::class)->getVolumeMetrics($this->buildFilters());
    }

    #[Computed]
    public function precisionMetrics(): PrecisionMetricsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return PrecisionMetricsDTO::empty();
        }

        return app(DashboardMetricsService::class)->getPrecisionMetrics($this->buildFilters());
    }

    #[Computed]
    public function geographicMetrics(): GeographicMetricsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return GeographicMetricsDTO::empty();
        }

        return app(DashboardMetricsService::class)->getGeographicMetrics($this->buildFilters());
    }

    #[Computed]
    public function recentActivity(): RecentActivityDTO
    {
        if (! $this->mostrarEstadisticas) {
            return RecentActivityDTO::empty();
        }

        return app(DashboardMetricsService::class)->getRecentActivity($this->buildFilters());
    }

    #[Computed]
    public function trendIndicators(): TrendIndicatorsDTO
    {
        if (! $this->mostrarEstadisticas) {
            return TrendIndicatorsDTO::empty();
        }

        return app(DashboardMetricsService::class)->getTrendIndicators($this->buildFilters());
    }

    #[Computed]
    public function topFailingPositions(): array
    {
        if (! $this->mostrarEstadisticas) {
            return [];
        }

        return app(DashboardMetricsService::class)->getTopFailingPositions($this->buildFilters());
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
        $scraperLog = LogScript::ultimaEjecucion('scraper');
        $pepLog = LogScript::ultimaEjecucion('pep_monitor');

        return view('livewire.dashboard', [
            'totalResultados' => ResultadoScraping::count(),
            'resultadosHoy' => ResultadoScraping::whereDate('fecha_encontrado', today())->count(),
            'resultadosHoyPorCat' => ResultadoScraping::whereDate('fecha_encontrado', today())
                ->selectRaw('categoria, COUNT(*) as total')
                ->groupBy('categoria')
                ->orderByRaw("CASE WHEN categoria = 'PEP' THEN 1 WHEN categoria = 'OPI' THEN 2 ELSE 3 END, categoria")
                ->pluck('total', 'categoria'),
            'resultadosSinLeer' => ResultadoScraping::where('leido', false)->count(),
            'totalFuentes' => Fuente::where('activo', true)->count(),
            'cambiosSinRevisar' => Cambio::where('revisado', false)->conPersona()->count(),
            'totalSitios' => SitioWeb::where('activo', true)->count(),
            'ultimosResultados' => ResultadoScraping::with('sitio')
                ->orderBy('fecha_encontrado', 'desc')
                ->limit(5)
                ->get(),
            'ultimosCambios' => Cambio::with('fuente')
                ->orderBy('fecha', 'desc')
                ->limit(5)
                ->get(),
            'scraperEjecutando' => LogScript::estaEjecutando('scraper'),
            'pepEjecutando' => LogScript::estaEjecutando('pep_monitor'),
            'scraperLog' => $scraperLog,
            'pepLog' => $pepLog,
        ]);
    }
}
