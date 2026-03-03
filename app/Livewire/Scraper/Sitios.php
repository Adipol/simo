<?php

namespace App\Livewire\Scraper;

use App\Models\Pais;
use App\Models\SitioWeb;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Sitios Web'])]
class Sitios extends Component
{
    use WithPagination;

    public string $busqueda = '';
    public string $filtroPais = '';
    public string $filtroActivo = '';

    // Formulario
    public bool $modalAbierto = false;
    public ?int $editandoId = null;
    public string $url = '';
    public string $nombre = '';
    public string $pais = 'BO';
    public string $selector_links = '';
    public string $selector_article = '';
    public bool $activo = true;

    protected function rules(): array
    {
        $unique = $this->editandoId
            ? 'unique:sitios_web,url,' . $this->editandoId
            : 'unique:sitios_web,url';

        return [
            'url'              => ['required', 'url', 'max:500', $unique],
            'nombre'           => ['required', 'string', 'max:200'],
            'pais'             => ['required', 'exists:paises,codigo'],
            'selector_links'   => ['nullable', 'string', 'max:200'],
            'selector_article' => ['nullable', 'string', 'max:200'],
            'activo'           => ['boolean'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $s = SitioWeb::findOrFail($id);
            $this->url              = $s->url;
            $this->nombre           = $s->nombre;
            $this->pais             = $s->pais;
            $this->selector_links   = $s->selector_links ?? '';
            $this->selector_article = $s->selector_article ?? '';
            $this->activo           = $s->activo;
        } else {
            $this->url = $this->nombre = $this->selector_links = $this->selector_article = '';
            $this->pais = 'BO';
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
            SitioWeb::where('id', $this->editandoId)->update($data);
        } else {
            SitioWeb::create($data);
        }

        $this->cerrarModal();
        $this->dispatch('guardado');
    }

    public function toggleActivo(int $id): void
    {
        $sitio = SitioWeb::findOrFail($id);
        $sitio->update(['activo' => !$sitio->activo]);
    }

    public function render()
    {
        $q = SitioWeb::with('pais');

        if ($this->busqueda) {
            $b = '%' . $this->busqueda . '%';
            $q->where(fn($s) => $s->where('nombre', 'like', $b)->orWhere('url', 'like', $b));
        }
        if ($this->filtroPais)  $q->where('pais', $this->filtroPais);
        if ($this->filtroActivo !== '') $q->where('activo', (bool)$this->filtroActivo);

        $sitios = $q->orderBy('nombre')->paginate(20);
        $paises = Pais::orderBy('nombre')->get();

        return view('livewire.scraper.sitios', compact('sitios', 'paises'));
    }
}
