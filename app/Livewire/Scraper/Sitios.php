<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Models\Pais;
use App\Models\SitioWeb;
use App\Services\Scraper\SiteManagementService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Sitios Web'])]
final class Sitios extends Component
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

    public string $pais = '';

    public string $selector_links = '';

    public string $selector_article = '';

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
        $unique = $this->editandoId
            ? 'unique:sitios_web,url,'.$this->editandoId
            : 'unique:sitios_web,url';

        return [
            'url' => ['required', 'url:http,https', 'max:500', $unique],
            'nombre' => ['required', 'string', 'max:200'],
            'pais' => ['required', 'exists:paises,codigo'],
            'selector_links' => ['nullable', 'string', 'max:200'],
            'selector_article' => ['nullable', 'string', 'max:200'],
            'activo' => ['boolean'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $s = SitioWeb::findOrFail($id);
            $this->url = $s->url;
            $this->nombre = $s->nombre;
            $this->pais = $s->pais;
            $this->selector_links = $s->selector_links ?? '';
            $this->selector_article = $s->selector_article ?? '';
            $this->activo = $s->activo;
        } else {
            $this->url = $this->nombre = $this->pais = $this->selector_links = $this->selector_article = '';
            $this->activo = true;
        }

        $this->modalAbierto = true;
    }

    public function cerrarModal(): void
    {
        $this->modalAbierto = false;
        $this->editandoId = null;
    }

    public function guardar(SiteManagementService $service): void
    {
        $this->authorize('gestionar sitios');

        $data = $this->validate();

        $site = $this->editandoId ? SitioWeb::findOrFail($this->editandoId) : null;
        $service->save($site, $data);

        $mensaje = $this->editandoId ? 'Sitio actualizado.' : 'Sitio creado.';
        $this->cerrarModal();
        $this->dispatch('notify', mensaje: $mensaje.' La validación se ejecutará en segundo plano.');
    }

    public function toggleActivo(int $id, SiteManagementService $service): void
    {
        $this->authorize('gestionar sitios');

        $sitio = SitioWeb::findOrFail($id);
        $service->toggleActive($sitio);
    }

    public function reintentarValidacion(int $id, SiteManagementService $service): void
    {
        $this->authorize('gestionar sitios');

        $service->retry(SitioWeb::findOrFail($id));
        $this->dispatch('notify', mensaje: 'Validación reenviada a la cola.');
    }

    #[Computed]
    public function paises(): Collection
    {
        return Pais::orderBy('nombre')->get();
    }

    public function render(): View
    {
        $q = SitioWeb::with('pais');

        if ($this->busqueda) {
            $b = '%'.$this->busqueda.'%';
            $q->where(fn ($s) => $s->where('nombre', 'like', $b)->orWhere('url', 'like', $b));
        }
        if ($this->filtroPais) {
            $q->where('pais', $this->filtroPais);
        }
        if ($this->filtroActivo !== '') {
            $q->where('activo', (bool) $this->filtroActivo);
        }

        $sitios = $q->orderBy('nombre')->paginate(20);

        return view('livewire.scraper.sitios', ['sitios' => $sitios, 'paises' => $this->paises]);
    }
}
