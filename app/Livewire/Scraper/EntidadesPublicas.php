<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Models\EntidadPublica;
use App\Models\Pais;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Entidades Públicas'])]
class EntidadesPublicas extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public string $filtroPais = '';

    public string $filtroActivo = '';

    // Formulario
    public bool $modalAbierto = false;

    public ?int $editandoId = null;

    public string $nombre = '';

    public string $sigla = '';

    public string $paisCodigo = '';

    public bool $activo = true;

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroPais(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroActivo(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200'],
            'sigla' => ['nullable', 'string', 'max:20'],
            'paisCodigo' => ['required', 'string', 'exists:paises,codigo'],
            'activo' => ['boolean'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $entidad = EntidadPublica::findOrFail($id);
            $this->nombre = $entidad->nombre;
            $this->sigla = $entidad->sigla ?? '';
            $this->paisCodigo = $entidad->pais_codigo;
            $this->activo = $entidad->activo;
        } else {
            $this->nombre = '';
            $this->sigla = '';
            $this->paisCodigo = '';
            $this->activo = true;
        }

        $this->modalAbierto = true;
    }

    public function cerrarModal(): void
    {
        $this->modalAbierto = false;
        $this->editandoId = null;
    }

    public function guardar(): void
    {
        $this->authorize('gestionar entidades publicas');

        $data = $this->validate();

        $payload = [
            'nombre' => $data['nombre'],
            'sigla' => $data['sigla'] ?: null,
            'pais_codigo' => $data['paisCodigo'],
            'activo' => $data['activo'],
        ];

        if ($this->editandoId) {
            EntidadPublica::where('id', $this->editandoId)->update($payload);
        } else {
            EntidadPublica::create($payload);
        }

        $mensaje = $this->editandoId ? 'Entidad actualizada.' : 'Entidad guardada.';
        $this->cerrarModal();
        $this->dispatch('notify', mensaje: $mensaje);
    }

    public function eliminar(int $id): void
    {
        $this->authorize('gestionar entidades publicas');

        EntidadPublica::findOrFail($id)->delete();
    }

    public function toggleActivo(int $id): void
    {
        $this->authorize('gestionar entidades publicas');

        $entidad = EntidadPublica::findOrFail($id);
        $entidad->update(['activo' => ! $entidad->activo]);
    }

    #[Computed]
    public function paises(): \Illuminate\Database\Eloquent\Collection
    {
        return Pais::orderBy('nombre')->get();
    }

    public function render()
    {
        $q = EntidadPublica::with('pais');

        if ($this->busqueda) {
            $b = '%'.$this->busqueda.'%';
            $q->where(fn ($s) => $s->where('nombre', 'like', $b)->orWhere('sigla', 'like', $b));
        }
        if ($this->filtroPais) {
            $q->where('pais_codigo', $this->filtroPais);
        }
        if ($this->filtroActivo !== '') {
            $q->where('activo', (bool) $this->filtroActivo);
        }

        $entidades = $q->orderBy('pais_codigo')->orderBy('nombre')->paginate(20);

        return view('livewire.scraper.entidades-publicas', [
            'entidades' => $entidades,
        ]);
    }
}
