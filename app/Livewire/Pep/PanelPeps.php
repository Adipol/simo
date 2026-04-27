<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Services\Pep\EventoPepArchiver;
use App\Services\Pep\ResultadoPersonaQueryService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Eventos PEP'])]
final class PanelPeps extends Component
{
    use WithPagination;

    // ─── Filtros URL-persistidos ───────────────────────────────────────────────

    #[Url]
    public string $filtroCategoria = '';

    #[Url]
    public string $fechaDesde = '';

    #[Url]
    public string $fechaHasta = '';

    #[Url]
    public bool $mostrarSinClasificar = false;

    // ─── Lifecycle hooks — reset pagination on filter change ──────────────────

    public function updatingFiltroCategoria(): void
    {
        $this->resetPage();
    }

    public function updatingFechaDesde(): void
    {
        $this->resetPage();
    }

    public function updatingFechaHasta(): void
    {
        $this->resetPage();
    }

    public function updatingMostrarSinClasificar(): void
    {
        $this->resetPage();
    }

    // ─── Computed — delegated to service, no DB in render() ───────────────────

    /**
     * @return LengthAwarePaginator<\App\Services\Pep\DTOs\EventoPepDTO>
     */
    #[Computed]
    public function eventos(): LengthAwarePaginator
    {
        return app(ResultadoPersonaQueryService::class)->getEventosAgrupados(
            categoria: $this->filtroCategoria ?: null,
            fechaDesde: $this->fechaDesde ?: null,
            fechaHasta: $this->fechaHasta ?: null,
            mostrarSinClasificar: $this->mostrarSinClasificar,
            perPage: 25,
            page: $this->getPage(),
        );
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Archive a group of resultados_scraping rows (snapshot semantics).
     *
     * @param  array<int>  $resultadoIds  Snapshot of IDs from the card.
     */
    public function archivar(array $resultadoIds): void
    {
        $count = app(EventoPepArchiver::class)->archivar($resultadoIds);

        // Bust #[Computed] cache so re-render reflects the archived state
        unset($this->eventos);

        if ($count > 0) {
            $this->dispatch('notify', mensaje: "{$count} artículo(s) archivado(s).", tipo: 'success');
        }
    }

    /**
     * Redirect to the Resultados panel filtered by the exact resultado IDs of this group.
     *
     * Accepts the IDs as a CSV string (e.g. "34,36,37") so Livewire can pass it
     * from the view via wire:click without array serialization issues.
     * Resultados receives it via #[Url(as: 'ids')] → filtroIds → whereIn('id', ...).
     *
     * Uses $this->redirectRoute() — the Livewire-aware redirect helper (void).
     */
    public function verArticulos(string $idsCsv): void
    {
        $this->redirectRoute('scraper.resultados', ['ids' => $idsCsv]);
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.pep.panel-peps');
    }
}
