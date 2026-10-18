<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Models\Fuente;
use App\Models\Pais;
use App\Services\Fuente\FuenteService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
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

    public string $autoridades_extractor = '';

    public bool $analizar_imagenes = false;

    public string $containerSelector = '';

    public string $rowSelector = '';

    public string $personSelector = '';

    public string $roleSelector = '';

    public string $minRows = '1';

    public string $maxRows = '200';

    public string $previewMessage = '';

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
            'autoridades_extractor' => ['nullable', 'in:divi_blurb,umsa_rectorado_profile,structured_css_v1'],
            'analizar_imagenes' => ['boolean'],
            'containerSelector' => [$this->autoridades_extractor === 'structured_css_v1' ? 'required' : 'nullable', 'string', 'max:500'],
            'rowSelector' => [$this->autoridades_extractor === 'structured_css_v1' ? 'required' : 'nullable', 'string', 'max:500'],
            'personSelector' => [$this->autoridades_extractor === 'structured_css_v1' ? 'required' : 'nullable', 'string', 'max:500'],
            'roleSelector' => [$this->autoridades_extractor === 'structured_css_v1' ? 'required' : 'nullable', 'string', 'max:500'],
            'minRows' => [$this->autoridades_extractor === 'structured_css_v1' ? 'required' : 'nullable', 'integer', 'between:1,200'],
            'maxRows' => [$this->autoridades_extractor === 'structured_css_v1' ? 'required' : 'nullable', 'integer', 'between:1,200', 'gte:minRows'],
        ];
    }

    public function abrirModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->previewMessage = '';
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
            $this->autoridades_extractor = $f->autoridades_extractor ?? '';
            $this->analizar_imagenes = (bool) $f->analizar_imagenes;
            $config = $f->autoridades_extractor === 'structured_css_v1' ? ($f->authority_extraction_config ?? []) : [];
            $this->containerSelector = $config['container_selector'] ?? '';
            $this->rowSelector = $config['row_selector'] ?? '';
            $this->personSelector = $config['person_selector'] ?? '';
            $this->roleSelector = $config['role_selector'] ?? '';
            $this->minRows = (string) ($config['min_rows'] ?? 1);
            $this->maxRows = (string) ($config['max_rows'] ?? 200);
        } else {
            $this->url = $this->nombre = $this->pais = $this->organismo = $this->selector_css = $this->autoridades_extractor = '';
            $this->nivel = 'nacional';
            $this->tipo = 'html';
            $this->activo = true;
            $this->analizar_imagenes = false;
            $this->containerSelector = $this->rowSelector = $this->personSelector = $this->roleSelector = '';
            $this->minRows = '1';
            $this->maxRows = '200';
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
        unset($data['containerSelector'], $data['rowSelector'], $data['personSelector'], $data['roleSelector'], $data['minRows'], $data['maxRows']);

        if ($this->autoridades_extractor === 'structured_css_v1') {
            foreach (['containerSelector', 'rowSelector', 'personSelector', 'roleSelector'] as $field) {
                if (trim($this->{$field}) === '') {
                    $this->addError($field, 'Este selector es obligatorio.');
                }
            }
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
            $data['authority_extraction_config'] = [
                'version' => 1,
                'container_selector' => trim($this->containerSelector),
                'row_selector' => trim($this->rowSelector),
                'person_selector' => trim($this->personSelector),
                'role_selector' => trim($this->roleSelector),
                'min_rows' => (int) $this->minRows,
                'max_rows' => (int) $this->maxRows,
            ];
        } else {
            $data['authority_extraction_config'] = null;
        }

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

    public function solicitarPreview(int $id, FuenteService $service): void
    {
        $this->resetValidation();
        try {
            $service->solicitarPreviewAutoridades($id);
            $this->previewMessage = 'Vista previa solicitada. El procesamiento es asíncrono.';
        } catch (ValidationException $exception) {
            $this->addError('preview', 'No se pudo solicitar la vista previa para esta fuente.');
        }
    }

    public function confirmarPreview(int $id, FuenteService $service): void
    {
        $this->resetValidation();
        try {
            $service->confirmarPreviewAutoridades($id);
            $this->previewMessage = 'Vista previa confirmada. Activá la fuente por separado.';
        } catch (ValidationException $exception) {
            $this->addError('preview', 'La vista previa ya no es válida; solicitá una nueva.');
        }
    }

    public function render(FuenteService $service): View
    {
        $fuentes = $service->paginar([
            'busqueda' => $this->busqueda,
            'nivel' => $this->filtroNivel,
            'activo' => $this->filtroActivo,
            'pais' => $this->filtroPais,
        ]);

        $previewRows = [];
        $pollPreviews = false;
        foreach ($fuentes as $fuente) {
            if ($fuente->autoridades_extractor !== 'structured_css_v1') {
                continue;
            }

            $preview = $fuente->authorityExtractionPreview;
            $current = $preview !== null
                && $preview->source_revision === $fuente->authority_extraction_revision
                && $preview->source_url === $fuente->url;
            $state = $preview === null ? null : ($current ? $preview->status : 'stale');
            $confirmable = $service->previewConfirmable($fuente, $preview);
            $pollPreviews = $pollPreviews || ($current && in_array($state, ['pending', 'running'], true));

            $previewRows[$fuente->id] = [
                'label' => match ($state) {
                    'pending' => 'Pendiente', 'running' => 'En proceso',
                    'ready' => $confirmable ? 'Lista' : 'No válida',
                    'incomplete' => 'Incompleta', 'error' => 'Error',
                    'stale' => 'Desactualizada', default => 'Sin solicitar',
                },
                'error' => $current && $state === 'error' ? match ($preview->error_code) {
                    'fetch_error' => 'No se pudo consultar la fuente',
                    'invalid_config' => 'Configuración no válida',
                    'attempts_exhausted' => 'Se agotaron los intentos',
                    default => 'No se pudo generar la vista previa',
                } : null,
                'roster' => $confirmable ? $preview->authorities_json : [],
                'showConfirm' => $confirmable
                    && $fuente->authority_extraction_confirmed_revision !== $fuente->authority_extraction_revision,
            ];
        }

        return view('livewire.pep.fuentes', [
            'fuentes' => $fuentes, 'paises' => $this->paises,
            'pollPreviews' => $pollPreviews, 'previewRows' => $previewRows,
        ]);
    }

    #[Computed]
    public function paises(): EloquentCollection
    {
        return Pais::where('activo', true)->orderBy('nombre')->get();
    }
}
