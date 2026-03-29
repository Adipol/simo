<?php

namespace App\Livewire\Configuracion;

use App\Models\Pais;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Países'])]
class Paises extends Component
{
    use WithPagination;

    // Filtros
    public string $busqueda = '';
    public string $filtroActivo = '';

    // Modal
    public bool $modalAbierto = false;
    public bool $esEdicion = false;

    // Campos del formulario
    public string $codigo = '';
    public string $nombre = '';
    public bool $activo = true;

    protected function rules(): array
    {
        $codigoRule = $this->esEdicion
            ? 'required|string|size:2|alpha'
            : 'required|string|size:2|alpha|unique:paises,codigo';

        return [
            'codigo' => $codigoRule,
            'nombre' => 'required|string|min:2|max:50',
            'activo' => 'boolean',
        ];
    }

    protected $messages = [
        'codigo.required' => 'El código es obligatorio.',
        'codigo.size'     => 'El código debe tener exactamente 2 letras.',
        'codigo.alpha'    => 'El código solo puede contener letras.',
        'codigo.unique'   => 'Ya existe un país con ese código.',
        'nombre.required' => 'El nombre es obligatorio.',
        'nombre.min'      => 'El nombre debe tener al menos 2 caracteres.',
    ];

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroActivo(): void
    {
        $this->resetPage();
    }

    public function abrirCrear(): void
    {
        $this->reset(['codigo', 'nombre']);
        $this->activo = true;
        $this->esEdicion = false;
        $this->resetValidation();
        $this->modalAbierto = true;
    }

    public function abrirEditar(string $codigo): void
    {
        $pais = Pais::findOrFail($codigo);
        $this->codigo  = $pais->codigo;
        $this->nombre  = $pais->nombre;
        $this->activo  = $pais->activo;
        $this->esEdicion = true;
        $this->resetValidation();
        $this->modalAbierto = true;
    }

    public function cerrarModal(): void
    {
        $this->modalAbierto = false;
        $this->resetValidation();
    }

    public function guardar(): void
    {
        $this->validate();

        $data = [
            'nombre' => trim($this->nombre),
            'activo' => $this->activo,
        ];

        if ($this->esEdicion) {
            Pais::where('codigo', $this->codigo)->update($data);
        } else {
            $data['codigo'] = strtoupper(trim($this->codigo));
            Pais::create($data);
        }

        $this->cerrarModal();
    }

    public function toggleActivo(string $codigo): void
    {
        $pais = Pais::findOrFail($codigo);
        $pais->update(['activo' => !$pais->activo]);
    }

    public function render()
    {
        $paises = Pais::withCount(['sitiosWeb', 'fuentes'])
            ->when($this->busqueda, fn($q) =>
                $q->where('nombre', 'like', '%' . $this->busqueda . '%')
                  ->orWhere('codigo', 'like', '%' . $this->busqueda . '%')
            )
            ->when($this->filtroActivo !== '', fn($q) =>
                $q->where('activo', (bool) $this->filtroActivo)
            )
            ->orderBy('nombre')
            ->paginate(20);

        return view('livewire.configuracion.paises', [
            'paises' => $paises,
        ]);
    }
}
