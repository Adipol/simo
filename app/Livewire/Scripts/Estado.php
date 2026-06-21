<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Models\ConfigScript;
use App\Models\LogScript;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Estado de Scripts'])]
class Estado extends Component
{
    use WithPagination;

    #[Url]
    public string $filtroScript = '';

    #[Url]
    public string $filtroEstado = '';

    public function updatedFiltroScript(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroEstado(): void
    {
        $this->resetPage();
    }

    // ─── Derived state via #[Computed] ─────────────────────────────────────────

    #[Computed]
    public function scraperUltimo(): ?LogScript
    {
        return LogScript::ultimaEjecucion('scraper');
    }

    #[Computed]
    public function pepUltimo(): ?LogScript
    {
        return LogScript::ultimaEjecucion('pep_monitor');
    }

    #[Computed]
    public function gacetaUltimo(): ?LogScript
    {
        return LogScript::ultimaEjecucion('gaceta');
    }

    #[Computed]
    public function scraperEjecutando(): bool
    {
        return LogScript::estaEjecutando('scraper');
    }

    #[Computed]
    public function pepEjecutando(): bool
    {
        return LogScript::estaEjecutando('pep_monitor');
    }

    #[Computed]
    public function gacetaEjecutando(): bool
    {
        return LogScript::estaEjecutando('gaceta');
    }

    #[Computed]
    public function scraperConfig(): ConfigScript
    {
        return ConfigScript::para('scraper');
    }

    #[Computed]
    public function gacetaConfig(): ConfigScript
    {
        return ConfigScript::para('gaceta');
    }

    /**
     * Unix timestamp of the scraper's current run start (for the JS progress counter).
     * Returns null when the scraper is not running.
     */
    #[Computed]
    public function scraperInicioTs(): ?int
    {
        return ($this->scraperEjecutando && $this->scraperUltimo)
            ? $this->scraperUltimo->inicio->timestamp
            : null;
    }

    /**
     * Scraper timeout ceiling in seconds (for the JS progress counter).
     * Falls back to config('scripts.scraper_timeout_fallback_minutes') × 60.
     */
    #[Computed]
    public function scraperTimeoutSeg(): int
    {
        $fallback = config('scripts.scraper_timeout_fallback_minutes', 30);

        return $this->scraperConfig
            ? $this->scraperConfig->timeout_minutos * 60
            : $fallback * 60;
    }

    // ─── Render ────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $q = LogScript::orderBy('inicio', 'desc');
        if ($this->filtroScript) {
            $q->where('script', $this->filtroScript);
        }
        if ($this->filtroEstado) {
            $q->where('estado', $this->filtroEstado);
        }

        return view('livewire.scripts.estado', [
            'logs'              => $q->paginate(30),
            'scraperUltimo'     => $this->scraperUltimo,
            'pepUltimo'         => $this->pepUltimo,
            'gacetaUltimo'      => $this->gacetaUltimo,
            'scraperEjecutando' => $this->scraperEjecutando,
            'pepEjecutando'     => $this->pepEjecutando,
            'gacetaEjecutando'  => $this->gacetaEjecutando,
            'gacetaConfig'      => $this->gacetaConfig,
            'scraperInicioTs'   => $this->scraperInicioTs,
            'scraperTimeoutSeg' => $this->scraperTimeoutSeg,
        ]);
    }
}
