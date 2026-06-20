<?php

declare(strict_types=1);

namespace App\Livewire\Gaceta;

use App\Models\GacetaEventoPep;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Human review queue for Gaceta PEP appointment events.
 *
 * Lists events with estado_revision = 'pendiente' awaiting human review.
 * A reviewer can filter by country (pais) and approve or reject each event
 * individually.
 *
 * Approve  → estado_revision = 'aprobado',  revisado_por + revisado_at stamped.
 * Reject   → estado_revision = 'rechazado', revisado_por + revisado_at stamped.
 *
 * Permission gate: 'gestionar resultados' (same as PrecisionDashboard).
 *
 * gaceta-decretos-collector · PR3 · Phase 6
 */
#[Layout('layouts.app', ['title' => 'Gaceta — Revisión de Eventos'])]
final class Eventos extends Component
{
    use WithPagination;

    /** Country filter — persisted in the URL query string. */
    #[Url]
    public string $pais = '';

    /**
     * Review-status filter — persisted in the URL query string.
     * Allowed values: 'pendiente' | 'aprobado' | 'rechazado' | '' (all).
     */
    #[Url]
    public string $estado = 'pendiente';

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function mount(): void
    {
        abort_unless(
            (bool) auth()->user()?->can('gestionar resultados'),
            403,
        );
    }

    // ─── Updating hooks ───────────────────────────────────────────────────────

    public function updatingPais(): void
    {
        $this->resetPage();
    }

    public function updatingEstado(): void
    {
        $this->resetPage();
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

    /**
     * Paginated list of events filtered by estado and país.
     *
     * When estado is non-empty, restricts to that estado_revision value.
     * When estado is '' all statuses are included (history view).
     * Eagerly loads gacetaNorma and revisadoPor to avoid N+1 in the view.
     * Never queries the DB inside render() — memoized by Livewire's #[Computed].
     *
     * @return LengthAwarePaginator<GacetaEventoPep>
     */
    #[Computed]
    public function eventos(): LengthAwarePaginator
    {
        return GacetaEventoPep::query()
            ->with(['gacetaNorma', 'revisadoPor'])
            ->when($this->estado !== '', fn ($q) => $q->where('estado_revision', $this->estado))
            ->when($this->pais !== '', fn ($q) => $q->porPais($this->pais))
            ->orderBy('created_at', 'asc')
            ->paginate(20);
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Approve a single event: stamps it as 'aprobado' and records the reviewer.
     */
    public function aprobar(int $id): void
    {
        GacetaEventoPep::where('id', $id)->update([
            'estado_revision' => 'aprobado',
            'revisado_por'    => auth()->id(),
            'revisado_at'     => now(),
        ]);

        unset($this->eventos);

        $this->dispatch('notify', mensaje: 'Evento aprobado.', tipo: 'success');
    }

    /**
     * Reject a single event: stamps it as 'rechazado' and records the reviewer.
     */
    public function rechazar(int $id): void
    {
        GacetaEventoPep::where('id', $id)->update([
            'estado_revision' => 'rechazado',
            'revisado_por'    => auth()->id(),
            'revisado_at'     => now(),
        ]);

        unset($this->eventos);

        $this->dispatch('notify', mensaje: 'Evento rechazado.', tipo: 'success');
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.gaceta.eventos', [
            'eventos' => $this->eventos,
        ]);
    }
}
