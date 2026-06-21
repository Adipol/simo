<?php

declare(strict_types=1);

namespace App\Livewire\Gaceta;

use App\Models\GacetaEventoPep;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only PEP person-profile view.
 *
 * Groups gaceta_eventos_pep by persona_nombre_normalizado so operators can
 * answer "is X a PEP? what is their current cargo? what is their history?".
 *
 * One row per distinct normalized name. Rejected events are excluded (rechazado
 * = explicitly not a PEP). aprobado and pendiente events both count.
 *
 * Cargo titular = the cargo of the most recent non-interim event. Interim
 * designations are listed separately in the detail panel. If a person has
 * only interim events their titular cargo shows as null.
 *
 * Permission gate: 'gestionar resultados' (same as Eventos/Normas).
 *
 * gaceta-personas-view
 */
#[Layout('layouts.app', ['title' => 'Gaceta — Personas (PEP)'])]
final class Personas extends Component
{
    use WithPagination;

    /** Name search — persisted in the URL query string. */
    #[Url]
    public string $buscar = '';

    /** Country filter — persisted in the URL query string. */
    #[Url]
    public string $pais = '';

    /**
     * Normalized name of the person whose detail panel is currently open.
     * Empty string means no person is selected.
     * Not persisted in URL (UI state only).
     */
    public string $personaSeleccionada = '';

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function mount(): void
    {
        abort_unless(
            (bool) auth()->user()?->can('gestionar resultados'),
            403,
        );
    }

    // ─── Updating hooks ───────────────────────────────────────────────────────

    public function updatingBuscar(): void
    {
        $this->resetPage();
    }

    public function updatingPais(): void
    {
        $this->resetPage();
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

    /**
     * Paginated list of persons grouped by persona_nombre_normalizado.
     *
     * Each row is a stdClass with:
     *   persona_nombre_normalizado, persona_nombre (display, from most recent event),
     *   total_designaciones (count), desde/hasta (min/max fecha_publicacion),
     *   cargo_titular (latest non-interim cargo, or null if only interim events).
     *
     * Excludes rechazado events. Applies optional pais and buscar filters.
     * Ordered by most-recent event date descending.
     *
     * Correlated subqueries in SELECT avoid N+1 while keeping a single round-trip.
     *
     * @return LengthAwarePaginator<object>
     */
    #[Computed]
    public function personas(): LengthAwarePaginator
    {
        return DB::table('gaceta_eventos_pep as e')
            ->join('gaceta_normas as n', 'n.id', '=', 'e.gaceta_norma_id')
            ->where('e.estado_revision', '!=', 'rechazado')
            ->when($this->pais !== '', fn ($q) => $q->where('e.pais', $this->pais))
            ->when(
                $this->buscar !== '',
                fn ($q) => $q->where(
                    'e.persona_nombre_normalizado',
                    'like',
                    '%' . Str::lower(Str::ascii($this->buscar)) . '%',
                ),
            )
            ->selectRaw(
                "e.persona_nombre_normalizado,
                COUNT(*) AS total_designaciones,
                MIN(n.fecha_publicacion) AS desde,
                MAX(n.fecha_publicacion) AS hasta,
                (
                    SELECT e2.persona_nombre
                    FROM gaceta_eventos_pep e2
                    JOIN gaceta_normas n2 ON n2.id = e2.gaceta_norma_id
                    WHERE e2.persona_nombre_normalizado = e.persona_nombre_normalizado
                      AND e2.estado_revision != 'rechazado'
                    ORDER BY n2.fecha_publicacion DESC, e2.id DESC
                    LIMIT 1
                ) AS persona_nombre,
                (
                    SELECT e3.cargo
                    FROM gaceta_eventos_pep e3
                    JOIN gaceta_normas n3 ON n3.id = e3.gaceta_norma_id
                    WHERE e3.persona_nombre_normalizado = e.persona_nombre_normalizado
                      AND e3.estado_revision != 'rechazado'
                      AND e3.interino = ?
                    ORDER BY n3.fecha_publicacion DESC, e3.id DESC
                    LIMIT 1
                ) AS cargo_titular",
                [false], // bound parameter — PDO casts per engine (0 on SQLite, false on PgSQL)
            )
            ->groupBy('e.persona_nombre_normalizado')
            ->orderByRaw('MAX(n.fecha_publicacion) DESC, e.persona_nombre_normalizado ASC')
            ->paginate(20)
            ->through(function (object $row): object {
                // Normalize date strings to Y-m-d regardless of engine/version.
                // SQLite may return "2024-01-10 00:00:00"; PostgreSQL returns "2024-01-10".
                $row->desde = isset($row->desde)
                    ? Carbon::parse($row->desde)->format('Y-m-d')
                    : null;
                $row->hasta = isset($row->hasta)
                    ? Carbon::parse($row->hasta)->format('Y-m-d')
                    : null;

                return $row;
            });
    }

    /**
     * Full event history for the selected person, newest first.
     *
     * Uses a JOIN for ordering by decree date and eager-loads gacetaNorma
     * to provide decree number + PDF URL in the detail table without N+1.
     *
     * Returns an empty Collection when no person is selected.
     *
     * @return Collection<int, GacetaEventoPep>
     */
    #[Computed]
    public function detalle(): Collection
    {
        if ($this->personaSeleccionada === '') {
            return collect();
        }

        return GacetaEventoPep::query()
            ->with('gacetaNorma')
            ->where('persona_nombre_normalizado', $this->personaSeleccionada)
            ->where('estado_revision', '!=', 'rechazado')
            ->join('gaceta_normas', 'gaceta_normas.id', '=', 'gaceta_eventos_pep.gaceta_norma_id')
            ->orderBy('gaceta_normas.fecha_publicacion', 'desc')
            ->orderBy('gaceta_eventos_pep.id', 'desc')
            ->select('gaceta_eventos_pep.*')
            ->get();
    }

    /**
     * Cargo from the most recent non-interim event for the selected person,
     * with a fallback to the referenced titular cargo from interim events.
     *
     * Resolution order:
     *   1. Latest non-interim (interino=false) appointment cargo — a real titular
     *      designation recorded in the gazette.
     *   2. Latest non-null cargo_referenciado among interim events — the permanent
     *      role mentioned as context in an interim decree (e.g. "Ministro de la
     *      Presidencia" in "Desígnese MINISTRO INTERINO DE X … mientras dure…").
     *   3. null — the person has only interim events with no referenced cargo.
     *
     * The detalle collection is already ordered newest-first, so first() naturally
     * picks the most recent event that satisfies each condition.
     *
     * Returns null when no person is selected.
     */
    #[Computed]
    public function cargoTitular(): ?string
    {
        if ($this->personaSeleccionada === '') {
            return null;
        }

        // Primary: latest non-interim appointment
        $titular = $this->detalle->first(fn ($e) => ! $e->interino)?->cargo;
        if ($titular !== null) {
            return $titular;
        }

        // Fallback: referenced titular cargo from latest interim event that has one
        return $this->detalle
            ->filter(fn ($e) => $e->interino && $e->cargo_referenciado !== null)
            ->first()
            ?->cargo_referenciado;
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Toggle the detail panel for the given normalized name.
     * Clicking the same person again collapses the panel (toggle).
     */
    public function seleccionar(string $normalizado): void
    {
        $this->personaSeleccionada = $this->personaSeleccionada === $normalizado
            ? ''
            : $normalizado;

        // Invalidate computed cache so render() picks up the new selection.
        unset($this->detalle, $this->cargoTitular);
    }

    // ─── Render ───────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.gaceta.personas', [
            'personas'     => $this->personas,
            'detalle'      => $this->detalle,
            'cargoTitular' => $this->cargoTitular,
        ]);
    }
}
