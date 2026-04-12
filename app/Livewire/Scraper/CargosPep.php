<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Enums\EntidadTipo;
use App\Models\CargoPep;
use App\Models\Pais;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Cargos PEP'])]
class CargosPep extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public string $filtroPais = '';

    public string $filtroCategoria = '';

    public string $filtroEntidadTipo = '';

    public string $filtroActivo = '';

    // Formulario
    public bool $modalAbierto = false;

    public ?int $editandoId = null;

    public string $nombre = '';

    public string $paisCodigo = '';

    public string $categoria = '';

    public string $entidadTipo = '';

    public bool $activo = true;

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroPais(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroCategoria(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroEntidadTipo(): void
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
            'paisCodigo' => ['required', 'string', 'exists:paises,codigo'],
            'categoria' => ['required', 'string', 'max:50'],
            'entidadTipo' => ['required', Rule::enum(EntidadTipo::class)],
            'activo' => ['boolean'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $cargo = CargoPep::findOrFail($id);
            $this->nombre = $cargo->nombre;
            $this->paisCodigo = $cargo->pais_codigo;
            $this->categoria = $cargo->categoria;
            $this->entidadTipo = $cargo->entidad_tipo->value;
            $this->activo = $cargo->activo;
        } else {
            $this->nombre = '';
            $this->paisCodigo = '';
            $this->categoria = '';
            $this->entidadTipo = '';
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
        $this->authorize('gestionar cargos pep');

        $data = $this->validate();

        $payload = [
            'nombre' => $data['nombre'],
            'pais_codigo' => $data['paisCodigo'],
            'categoria' => $data['categoria'],
            'entidad_tipo' => $data['entidadTipo'],
            'activo' => $data['activo'],
        ];

        if ($this->editandoId) {
            CargoPep::where('id', $this->editandoId)->update($payload);
        } else {
            CargoPep::create($payload);
        }

        $mensaje = $this->editandoId ? 'Cargo actualizado.' : 'Cargo guardado.';
        $this->cerrarModal();
        $this->dispatch('notify', mensaje: $mensaje);
    }

    public function eliminar(int $id): void
    {
        $this->authorize('gestionar cargos pep');

        CargoPep::findOrFail($id)->delete();
    }

    public function toggleActivo(int $id): void
    {
        $this->authorize('gestionar cargos pep');

        $cargo = CargoPep::findOrFail($id);
        $cargo->update(['activo' => ! $cargo->activo]);
    }

    #[Computed]
    public function paises(): \Illuminate\Database\Eloquent\Collection
    {
        return Pais::orderBy('nombre')->get();
    }

    #[Computed]
    public function categorias(): \Illuminate\Support\Collection
    {
        return CargoPep::distinct()->orderBy('categoria')->pluck('categoria');
    }

    #[Computed]
    public function entidadTipos(): array
    {
        return EntidadTipo::cases();
    }

    public function render()
    {
        $q = CargoPep::with('pais');

        if ($this->busqueda) {
            $b = '%'.$this->busqueda.'%';
            $q->where('nombre', 'like', $b);
        }
        if ($this->filtroPais) {
            $q->where('pais_codigo', $this->filtroPais);
        }
        if ($this->filtroCategoria) {
            $q->where('categoria', $this->filtroCategoria);
        }
        if ($this->filtroEntidadTipo) {
            $q->where('entidad_tipo', $this->filtroEntidadTipo);
        }
        if ($this->filtroActivo !== '') {
            $q->where('activo', (bool) $this->filtroActivo);
        }

        $cargos = $q->orderBy('pais_codigo')->orderBy('nombre')->paginate(20);

        return view('livewire.scraper.cargos-pep', [
            'cargos' => $cargos,
        ]);
    }
}
