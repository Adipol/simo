<?php

namespace App\Livewire\Scraper;

use App\Models\PalabraClave;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Palabras Clave'])]
class Keywords extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public string $filtroActivo = '';

    // Formulario
    public bool $modalAbierto = false;

    public ?int $editandoId = null;

    public string $keyword = '';

    public string $categoria = '';

    public bool $activo = true;

    public function updatingBusqueda(): void
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
            ? 'unique:palabras_clave,keyword,'.$this->editandoId
            : 'unique:palabras_clave,keyword';

        return [
            'keyword' => ['required', 'string', 'max:200', $unique],
            'categoria' => ['nullable', 'string', 'max:100'],
            'activo' => ['boolean'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $k = PalabraClave::findOrFail($id);
            $this->keyword = $k->keyword;
            $this->categoria = $k->categoria ?? '';
            $this->activo = $k->activo;
        } else {
            $this->keyword = $this->categoria = '';
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
        $data = $this->validate();

        if ($this->editandoId) {
            PalabraClave::where('id', $this->editandoId)->update($data);
        } else {
            PalabraClave::create($data);
        }

        $this->cerrarModal();
    }

    public function toggleActivo(int $id): void
    {
        $k = PalabraClave::findOrFail($id);
        $k->update(['activo' => ! $k->activo]);
    }

    public function render()
    {
        $q = PalabraClave::query();

        if ($this->busqueda) {
            $b = '%'.$this->busqueda.'%';
            $q->where(fn ($s) => $s->where('keyword', 'like', $b)->orWhere('categoria', 'like', $b));
        }
        if ($this->filtroActivo !== '') {
            $q->where('activo', (bool) $this->filtroActivo);
        }

        $keywords = $q->orderBy('keyword')->paginate(25);

        return view('livewire.scraper.keywords', compact('keywords'));
    }
}
