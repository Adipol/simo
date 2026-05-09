<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Models\Fuente;
use App\Models\Pais;
use App\Services\Fuente\FuenteService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Fuentes PEP'])]
class Fuentes extends Component
{
    use WithPagination;

    #[Url]
    public string $busqueda = '';

    #[Url]
    public string $filtroNivel = '';

    #[Url]
    public string $filtroActivo = '';

    #[Url]
    public string $filtroPais = '';

    // Formulario
    public bool $modalAbierto = false;

    public ?int $editandoId = null;

    public string $url = '';

    public string $nombre = '';

    public string $pais = '';

    public string $organismo = '';

    public string $nivel = 'nacional';

    public string $tipo = 'html';

    public bool $activo = true;

    public string $selector_css = '';

    public bool $analizar_imagenes = false;

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroNivel(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroActivo(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroPais(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        $unique = $this->editandoId
            ? 'unique:fuentes,url,'.$this->editandoId
            : 'unique:fuentes,url';

        return [
            'url' => ['required', 'url', 'max:500', $unique],
            'nombre' => ['nullable', 'string', 'max:300'],
            'pais' => ['nullable', 'string', 'size:2', 'exists:paises,codigo'],
            'organismo' => ['nullable', 'string', 'max:300'],
            'nivel' => ['required', 'in:nacional,regional,municipal,judicial,legislativo,otro'],
            'tipo' => ['required', 'in:html,pdf,js'],
            'activo' => ['boolean'],
            'selector_css' => ['nullable', 'string', 'max:500'],
            'analizar_imagenes' => ['boolean'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $f = Fuente::findOrFail($id);
            $this->url = $f->url;
            $this->nombre = $f->nombre ?? '';
            $this->pais = $f->pais ?? '';
            $this->organismo = $f->organismo ?? '';
            $this->nivel = $f->nivel;
            $this->tipo = $f->tipo;
            $this->activo = $f->activo;
            $this->selector_css = $f->selector_css ?? '';
            $this->analizar_imagenes = (bool) $f->analizar_imagenes;
        } else {
            $this->url = $this->nombre = $this->pais = $this->organismo = $this->selector_css = '';
            $this->nivel = 'nacional';
            $this->tipo = 'html';
            $this->activo = true;
            $this->analizar_imagenes = false;
        }

        $this->modalAbierto = true;
    }

    public function cerrarModal(): void
    {
        $this->modalAbierto = false;
        $this->editandoId = null;
    }

    public function guardar(FuenteService $service): void
    {
        $data = $this->validate();

        if ($this->editandoId) {
            $service->actualizar($this->editandoId, $data);
        } else {
            $service->crear($data);
        }

        $this->cerrarModal();
    }

    public function toggleActivo(int $id, FuenteService $service): void
    {
        $service->toggleActivo($id);
    }

    public function render(FuenteService $service): View
    {
        $fuentes = $service->paginar([
            'busqueda' => $this->busqueda,
            'nivel' => $this->filtroNivel,
            'activo' => $this->filtroActivo,
            'pais' => $this->filtroPais,
        ]);

        return view('livewire.pep.fuentes', ['fuentes' => $fuentes, 'paises' => $this->paises]);
    }

    #[Computed]
    public function paises(): EloquentCollection
    {
        return Pais::where('activo', true)->orderBy('nombre')->get();
    }
}
