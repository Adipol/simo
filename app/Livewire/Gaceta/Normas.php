<?php

declare(strict_types=1);

namespace App\Livewire\Gaceta;

use App\Models\GacetaEventoPep;
use App\Models\GacetaNorma;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Human review queue for flagged Gaceta decrees.
 *
 * Surfaces normas with estado_extraccion ∈ {requiere_revision, requiere_detalle}
 * so a reviewer can:
 *   - Discard the decree (non-appointment: abrogation, pardon, ratification).
 *   - Manually add individual PEP appointment events (repeatable for bulk decrees).
 *   - Mark the norma as fully resolved once all appointments are entered.
 *
 * Permission gate: 'gestionar resultados' (same as Eventos).
 */
#[Layout('layouts.app', ['title' => 'Gaceta — Normas a revisar'])]
final class Normas extends Component
{
    use WithPagination;

    /** Country filter — persisted in the URL query string. */
    #[Url]
    public string $pais = '';

    /**
     * Extraction-state filter — persisted in the URL query string.
     * Allowed values: '' | 'requiere_revision' | 'requiere_detalle'.
     */
    #[Url]
    public string $tipo = '';

    /** ID of the norma whose manual-extraction form is currently open (0 = none). */
    public int $formNormaId = 0;

    // ─── Manual extraction form fields ────────────────────────────────────────

    public string $personaNombre = '';

    public string $cargo = '';

    public bool $interino = false;

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

    public function updatingTipo(): void
    {
        $this->resetPage();
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

    /**
     * Paginated list of flagged normas awaiting human review.
     *
     * Includes only requiere_revision and requiere_detalle; excludes procesado,
     * descartado, and resuelto_manual. Eager-loads eventosPep to avoid N+1.
     *
     * @return LengthAwarePaginator<GacetaNorma>
     */
    #[Computed]
    public function normas(): LengthAwarePaginator
    {
        return GacetaNorma::query()
            ->with(['eventosPep'])
            ->whereIn('estado_extraccion', ['requiere_revision', 'requiere_detalle'])
            ->when($this->pais !== '', fn ($q) => $q->where('pais', $this->pais))
            ->when($this->tipo !== '', fn ($q) => $q->where('estado_extraccion', $this->tipo))
            ->orderBy('fecha_publicacion', 'desc')
            ->paginate(20);
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Discard a flagged norma (non-appointment decree: abrogation, pardon, ratification).
     * Stamps revisado_por + revisado_at and removes it from the queue.
     */
    public function descartar(int $normaId): void
    {
        GacetaNorma::where('id', $normaId)->update([
            'estado_extraccion' => 'descartado',
            'revisado_por'      => auth()->id(),
            'revisado_at'       => now(),
        ]);

        unset($this->normas);
    }

    /**
     * Toggle the manual-extraction form for the given norma.
     * Opens the form for that norma; closes it if the same norma is clicked again.
     * Resets form fields on every toggle.
     */
    public function toggleForm(int $normaId): void
    {
        $this->formNormaId   = $this->formNormaId === $normaId ? 0 : $normaId;
        $this->personaNombre = '';
        $this->cargo         = '';
        $this->interino      = false;
    }

    /**
     * Add a manual PEP appointment event for the given norma.
     *
     * Repeatable: the norma stays in the queue after each addition so multiple
     * appointees can be entered for bulk decrees. Call marcarResuelto() when done.
     */
    public function agregarEvento(int $normaId): void
    {
        $this->validate([
            'personaNombre' => ['required', 'string', 'max:150'],
            'cargo'         => ['required', 'string', 'max:150'],
        ]);

        $norma = GacetaNorma::findOrFail($normaId);

        GacetaEventoPep::create([
            'gaceta_norma_id'           => $normaId,
            'pais'                       => $norma->pais,
            'persona_nombre'             => $this->personaNombre,
            'persona_nombre_normalizado' => Str::lower(Str::ascii($this->personaNombre)),
            'cargo'                      => $this->cargo,
            'interino'                   => $this->interino,
            'tipo_evento'                => 'designacion',
            'estado_revision'            => 'aprobado',
            'revisado_por'               => auth()->id(),
            'revisado_at'                => now(),
        ]);

        $this->personaNombre = '';
        $this->cargo         = '';
        $this->interino      = false;

        unset($this->normas);
    }

    /**
     * Mark a norma as fully resolved: all appointments have been entered manually.
     * Stamps revisado_por + revisado_at and removes it from the queue.
     */
    public function marcarResuelto(int $normaId): void
    {
        GacetaNorma::where('id', $normaId)->update([
            'estado_extraccion' => 'resuelto_manual',
            'revisado_por'      => auth()->id(),
            'revisado_at'       => now(),
        ]);

        unset($this->normas);
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.gaceta.normas', [
            'normas' => $this->normas,
        ]);
    }
}
