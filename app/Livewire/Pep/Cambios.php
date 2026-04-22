<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Cambios PEP'])]
class Cambios extends Component
{
    use WithPagination;

    #[Url]
    public string $filtroFuente = '';

    #[Url]
    public string $filtroRevisado = '';

    #[Url]
    public string $filtroConPersona = 'si';

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
        Cambio::marcarComoRevisado($id);

        if ($this->verDiffId === $id) {
            $this->verDiffId = null;
        }
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

    public function render(): View
    {
        $q = Cambio::with('fuente')->orderBy('fecha', 'desc');

        if ($this->filtroFuente) {
            $q->where('fuente_id', $this->filtroFuente);
        }
        if ($this->filtroRevisado !== '') {
            $q->where('revisado', (bool) $this->filtroRevisado);
        }
        if ($this->filtroConPersona === 'si') {
            $q->conPersona();
        } elseif ($this->filtroConPersona === 'no') {
            $q->sinPersona();
        }
        if ($this->filtroRiesgo !== '') {
            $q->conRiesgo($this->filtroRiesgo);
        }

        return view('livewire.pep.cambios', [
            'cambios' => $q->paginate(20),
            'fuentes' => Fuente::orderBy('nombre')->get(['id', 'nombre', 'organismo']),
        ]);
    }
}
