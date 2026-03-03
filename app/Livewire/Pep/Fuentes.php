<?php

namespace App\Livewire\Pep;

use App\Models\Fuente;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Fuentes PEP'])]
class Fuentes extends Component
{
    use WithPagination;

    public string $busqueda = '';
    public string $filtroNivel = '';
    public string $filtroActivo = '';

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

    protected function rules(): array
    {
        $unique = $this->editandoId
            ? 'unique:fuentes,url,' . $this->editandoId
            : 'unique:fuentes,url';

        return [
            'url'         => ['required', 'url', 'max:500', $unique],
            'nombre'      => ['nullable', 'string', 'max:300'],
            'pais'        => ['nullable', 'string', 'max:100'],
            'organismo'   => ['nullable', 'string', 'max:300'],
            'nivel'       => ['required', 'in:nacional,regional,municipal,judicial,legislativo,otro'],
            'tipo'        => ['required', 'in:html,pdf,js'],
            'activo'      => ['boolean'],
            'selector_css'=> ['nullable', 'string', 'max:500'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->editandoId = $id;

        if ($id) {
            $f = Fuente::findOrFail($id);
            $this->url          = $f->url;
            $this->nombre       = $f->nombre ?? '';
            $this->pais         = $f->pais ?? '';
            $this->organismo    = $f->organismo ?? '';
            $this->nivel        = $f->nivel;
            $this->tipo         = $f->tipo;
            $this->activo       = $f->activo;
            $this->selector_css = $f->selector_css ?? '';
        } else {
            $this->url = $this->nombre = $this->pais = $this->organismo = $this->selector_css = '';
            $this->nivel = 'nacional';
            $this->tipo = 'html';
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
            Fuente::where('id', $this->editandoId)->update($data);
        } else {
            Fuente::create($data);
        }

        $this->cerrarModal();
    }

    public function toggleActivo(int $id): void
    {
        $f = Fuente::findOrFail($id);
        $f->update(['activo' => !$f->activo]);
    }

    public function render()
    {
        $q = Fuente::withCount(['cambios as cambios_sin_revisar' => fn($q) => $q->where('revisado', false)]);

        if ($this->busqueda) {
            $b = '%' . $this->busqueda . '%';
            $q->where(fn($s) => $s->where('nombre', 'like', $b)
                ->orWhere('url', 'like', $b)
                ->orWhere('organismo', 'like', $b));
        }
        if ($this->filtroNivel)  $q->where('nivel', $this->filtroNivel);
        if ($this->filtroActivo !== '') $q->where('activo', (bool)$this->filtroActivo);

        $fuentes = $q->orderBy('nombre')->paginate(20);

        return view('livewire.pep.fuentes', compact('fuentes'));
    }
}
