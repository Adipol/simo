<?php

namespace App\Livewire\Pep;

use App\Models\Cambio;
use App\Models\Fuente;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Cambios PEP'])]
class Cambios extends Component
{
    use WithPagination;

    public string $filtroFuente = '';

    public string $filtroRevisado = '';

    public ?int $verDiffId = null;

    public function updatingFiltroFuente(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroRevisado(): void
    {
        $this->resetPage();
    }

    public function marcarRevisado(int $id): void
    {
        Cambio::where('id', $id)->update(['revisado' => true]);

        // Si estabamos viendo el diff de este cambio, cerrarlo
        if ($this->verDiffId === $id) {
            $this->verDiffId = null;
        }
    }

    public function toggleDiff(int $id): void
    {
        $this->verDiffId = ($this->verDiffId === $id) ? null : $id;
    }

    public function render()
    {
        $q = Cambio::with('fuente')->orderBy('fecha', 'desc');

        if ($this->filtroFuente) {
            $q->where('fuente_id', $this->filtroFuente);
        }
        if ($this->filtroRevisado !== '') {
            $q->where('revisado', (bool) $this->filtroRevisado);
        }

        $cambios = $q->paginate(20);
        $fuentes = Fuente::orderBy('nombre')->get(['id', 'nombre', 'organismo']);

        $cambioDetalle = $this->verDiffId
            ? Cambio::with('fuente')->find($this->verDiffId)
            : null;

        return view('livewire.pep.cambios', compact('cambios', 'fuentes', 'cambioDetalle'));
    }
}
