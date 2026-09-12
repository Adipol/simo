<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Cambios PEP'])]
final class Cambios extends Component
{
    use WithPagination;

    #[Url]
    public string $filtroFuente = '';

    #[Url]
    public string $filtroRevisado = '';

    #[Url]
    public string $feed = 'review';

    #[Url]
    public string $filtroConPersona = '';

    #[Url]
    public string $filtroRiesgo = '';

    public ?int $verDiffId = null;

    public function updatingFiltroFuente(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroRevisado(): void
    {
        $this->resetPage();
    }

    public function updatingFeed(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroConPersona(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroRiesgo(): void
    {
        $this->resetPage();
    }

    public function marcarRevisado(int $id): void
    {
        $this->authorize('marcar revisado pep');

        Cambio::marcarComoRevisado($id);

        if ($this->verDiffId === $id) {
            $this->verDiffId = null;
        }

        unset($this->cambios);
    }

    public function toggleDiff(int $id): void
    {
        $this->verDiffId = ($this->verDiffId === $id) ? null : $id;
        unset($this->cambioDetalle);
    }

    public function riesgoColor(string $riesgo): string
    {
        return match ($riesgo) {
            'alto' => 'bg-red-50 text-red-600',
            'medio' => 'bg-amber-50 text-amber-600',
            default => 'bg-emerald-50 text-emerald-600',
        };
    }

    #[Computed]
    public function cambioDetalle(): ?Cambio
    {
        return $this->verDiffId
            ? Cambio::with('fuente')->find($this->verDiffId)
            : null;
    }

    #[Computed]
    public function cambios(): LengthAwarePaginator
    {
        $query = Cambio::query()
            ->with('fuente')
            ->orderByDesc('fecha');

        match ($this->feed) {
            'review' => $query->reviewFeed(),
            'all' => $query,
            default => $query->primaryFeed(),
        };

        $query
            ->when(
                $this->filtroFuente !== '',
                fn (Builder $builder): Builder => $builder->where('fuente_id', $this->filtroFuente),
            )
            ->when(
                $this->filtroRevisado !== '',
                fn (Builder $builder): Builder => $builder->where('revisado', (bool) $this->filtroRevisado),
            )
            ->when(
                $this->filtroConPersona === 'si',
                fn (Builder $builder): Builder => $builder->conPersona(),
            )
            ->when(
                $this->filtroConPersona === 'no',
                fn (Builder $builder): Builder => $builder->sinPersona(),
            )
            ->when(
                $this->filtroRiesgo !== '',
                fn (Builder $builder): Builder => $builder->conRiesgo($this->filtroRiesgo),
            );

        return $query->paginate(20);
    }

    /** @return Collection<int,Fuente> */
    #[Computed]
    public function fuentes(): Collection
    {
        return Fuente::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'organismo']);
    }

    public function render(): View
    {
        return view('livewire.pep.cambios', [
            'cambios' => $this->cambios,
            'fuentes' => $this->fuentes,
        ]);
    }
}
