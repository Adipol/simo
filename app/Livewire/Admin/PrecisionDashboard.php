<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Services\Dashboard\DTOs\DescartadosMetricsDTO;
use App\Services\DescartadosAnalisisService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Analytics dashboard for discarded scraping precision metrics.
 *
 * Renders 4 Chart.js charts driven by DescartadosAnalisisService:
 *   1. Precision general (card + current snapshot)
 *   2. Top lemas problemáticos (horizontal bar)
 *   3. Top sitios problemáticos (horizontal bar)
 *   4. Confianza Gemini vs % descartado (vertical bar, 4 buckets)
 *
 * Permission gate: 'gestionar resultados' (via mount abort_unless).
 * Auto-refreshes every 300s (aligned with cache TTL).
 *
 * feedback-loop-from-descartados · PR-C · design §Livewire
 */
#[Layout('layouts.app', ['title' => 'Análisis de Precisión'])]
final class PrecisionDashboard extends Component
{
    public function mount(): void
    {
        abort_unless(
            (bool) auth()->user()?->can('gestionar resultados'),
            403,
        );
    }

    // ─── Computed properties ──────────────────────────────────────────────────

    #[Computed]
    public function metricsGenerales(): DescartadosMetricsDTO
    {
        return app(DescartadosAnalisisService::class)->precisionGeneral();
    }

    #[Computed]
    public function topLemas(): Collection
    {
        return app(DescartadosAnalisisService::class)->topLemasProblematicos();
    }

    #[Computed]
    public function topSitios(): Collection
    {
        return app(DescartadosAnalisisService::class)->topSitiosProblematicos();
    }

    #[Computed]
    public function driftPorKeyword(): Collection
    {
        return app(DescartadosAnalisisService::class)->driftPorKeyword();
    }

    #[Computed]
    public function confianzaBuckets(): Collection
    {
        return app(DescartadosAnalisisService::class)->confianzaGeminiVsDescartado();
    }

    // ─── Chart data — pre-shaped for Js::from() in Blade ─────────────────────

    /**
     * @return array<int, array{label: string, value: float}>
     */
    #[Computed]
    public function topLemasChart(): array
    {
        return $this->topLemas
            ->map(fn ($d) => ['label' => $d->keyword, 'value' => $d->pctDescartado])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: float}>
     */
    #[Computed]
    public function topSitiosChart(): array
    {
        return $this->topSitios
            ->map(fn ($d) => ['label' => $d->sitioNombre, 'value' => $d->pctDescartado])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: float}>
     */
    #[Computed]
    public function confianzaChart(): array
    {
        return $this->confianzaBuckets
            ->map(fn ($d) => ['label' => 'Confianza '.$d->bucket, 'value' => $d->pctDescartado])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: float}>
     */
    #[Computed]
    public function driftChart(): array
    {
        return $this->driftPorKeyword
            ->map(fn ($d) => ['label' => $d->keyword, 'value' => $d->driftPpt ?? 0.0])
            ->values()
            ->all();
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Flush service cache and invalidate all #[Computed] properties so the
     * next render fetches fresh data from the database.
     *
     * REQ-4 / SCN-4.1 — feedback-loop-from-descartados
     */
    public function refrescarAhora(DescartadosAnalisisService $service): void
    {
        $service->flushCache();

        unset($this->metricsGenerales);
        unset($this->topLemas);
        unset($this->topSitios);
        unset($this->driftPorKeyword);
        unset($this->confianzaBuckets);
        unset($this->topLemasChart);
        unset($this->topSitiosChart);
        unset($this->confianzaChart);
        unset($this->driftChart);
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.admin.precision-dashboard');
    }
}
