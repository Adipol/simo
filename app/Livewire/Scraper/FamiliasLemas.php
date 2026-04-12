<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Enums\CategoriaFamilia;
use App\Models\FamiliaLema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Familias de lemas'])]
class FamiliasLemas extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public string $filtroCategoria = '';

    public string $filtroActivo = '';

    // Formulario
    public bool $modalAbierto = false;

    public ?int $editandoId = null;

    public string $raiz = '';

    public string $variantesRaw = '';

    public string $categoria = '';

    public bool $activo = true;

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroCategoria(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroActivo(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        $unique = $this->editandoId
            ? 'unique:familias_lemas,raiz,'.$this->editandoId
            : 'unique:familias_lemas,raiz';

        return [
            'raiz' => ['required', 'string', 'max:100', $unique],
            'variantesRaw' => ['required', 'string'],
            'categoria' => ['required', Rule::enum(CategoriaFamilia::class)],
            'activo' => ['boolean'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $f = FamiliaLema::findOrFail($id);
            $this->raiz = $f->raiz;
            $this->variantesRaw = implode("\n", $f->variantes);
            $this->categoria = $f->categoria->value;
            $this->activo = $f->activo;
        } else {
            $this->raiz = '';
            $this->variantesRaw = '';
            $this->categoria = '';
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
        $this->authorize('gestionar familias lemas');

        $data = $this->validate();

        $variantes = array_values(array_filter(
            array_map('trim', explode("\n", $data['variantesRaw']))
        ));

        abort_if(count($variantes) < 1, 422, 'Al menos una variante requerida');

        $payload = [
            'raiz' => $data['raiz'],
            'variantes' => $variantes,
            'categoria' => $data['categoria'],
            'activo' => $data['activo'],
        ];

        if ($this->editandoId) {
            FamiliaLema::where('id', $this->editandoId)->update($payload);
        } else {
            FamiliaLema::create($payload);
        }

        $mensaje = $this->editandoId ? 'Familia actualizada.' : 'Familia guardada.';
        $this->cerrarModal();
        $this->dispatch('notify', mensaje: $mensaje);
    }

    public function eliminar(int $id): void
    {
        $this->authorize('gestionar familias lemas');

        FamiliaLema::findOrFail($id)->delete();
    }

    public function toggleActivo(int $id): void
    {
        $this->authorize('gestionar familias lemas');

        $familia = FamiliaLema::findOrFail($id);
        $familia->update(['activo' => ! $familia->activo]);
    }

    #[Computed]
    public function categorias(): array
    {
        return CategoriaFamilia::cases();
    }

    public function render()
    {
        $q = FamiliaLema::query();

        if ($this->busqueda) {
            $b = '%'.$this->busqueda.'%';
            $q->where(fn ($s) => $s->where('raiz', 'like', $b));
        }
        if ($this->filtroCategoria) {
            $q->byCategoria($this->filtroCategoria);
        }
        if ($this->filtroActivo !== '') {
            $q->where('activo', (bool) $this->filtroActivo);
        }

        $familias = $q->orderBy('raiz')->paginate(20);

        return view('livewire.scraper.familias-lemas', [
            'familias' => $familias,
        ]);
    }
}
