<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Exceptions\PepDirectory\{ConfigurationConflictException, PreviewCompilationException};
use App\Models\{PepDirectoryCapture, PepDirectoryPreview, PepDirectorySource, User};
use App\Services\PepDirectory\{ConfigurationService, DirectoryPreviewService, PreviewCompiler};
use App\Services\PepDirectory\DTOs\{ConfigurationDecisionData, DirectoryMappingData};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\{Computed, Locked};
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

final class ConfiguracionDirectorio extends Component
{
    #[Locked]
    public ?int $sourceId = null;
    #[Locked]
    public ?int $captureId = null;
    #[Locked]
    public ?int $previewId = null;
    #[Locked]
    public ?int $displayedVersion = null;
    #[Locked]
    public bool $conflict = false;
    #[Locked]
    public bool $dirty = false;
    #[Locked]
    public string $notice = '';

    public string $directory = '';
    public string $record = '';
    public string $section = '';
    public string $columns = '';
    public array $fields = [];
    public string $reason = '';
    protected User $actor;

    public function boot(): void
    {
        // Initial AND hydrated access: gate before any PEP query, including absent schema.
        abort_unless(config('pep_directory.ui_enabled', false) === true, 404);
        abort_unless(auth()->id() !== null, 401);
        $actor = User::query()->find(auth()->id());
        abort_unless($actor?->activo, 403);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        abort_unless($actor->checkPermissionTo('ver cambios pep'), 403);
        $this->actor = $actor;
    }

    #[Computed]
    public function sources(): Collection
    {
        return PepDirectorySource::query()->with('fuente')->where('configurable', true)->orderBy('id')->get();
    }

    #[Computed]
    public function selectedSource(): ?PepDirectorySource
    {
        return $this->sourceId === null ? null : PepDirectorySource::query()->where('configurable', true)->findOrFail($this->sourceId);
    }

    #[Computed]
    public function captures(): Collection
    {
        return $this->sourceId === null ? collect() : PepDirectoryCapture::query()->where('source_id', $this->selectedSource->id)->orderByDesc('id')->get();
    }

    #[Computed]
    public function preview(): ?PepDirectoryPreview
    {
        return $this->previewId === null ? null : PepDirectoryPreview::query()->where('source_id', $this->selectedSource->id)->findOrFail($this->previewId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return $this->actor->checkPermissionTo('gestionar fuentes');
    }

    #[Computed]
    public function canGenerate(): bool
    {
        // Availability is NOT runtime proof. Never instantiate or execute a compiler on render.
        $interpreter = config('pep_directory.preview_interpreter');
        return app()->bound(PreviewCompiler::class) && is_string($interpreter) && str_starts_with($interpreter, '/');
    }

    public function selectSource(int $id): void
    {
        $source = PepDirectorySource::query()->where('configurable', true)->findOrFail($id);
        $this->sourceId = $source->id;
        $this->displayedVersion = $source->eligibility_version;
        $this->previewId = $source->current_preview_id;
        unset($this->selectedSource, $this->preview, $this->captures);
        $this->captureId = $this->preview?->capture_id ?? $this->captures->first()?->id;
        if ($this->captureId !== null) {
            PepDirectoryCapture::query()->where('source_id', $id)->findOrFail($this->captureId);
        }
        $configuration = $source->draftConfiguration;
        abort_if($configuration !== null && $configuration->source_id !== $id, 404);
        $mapping = $configuration?->declaration ?? [];
        foreach (['directory', 'record', 'section', 'columns'] as $key) {
            $this->{$key} = (string) ($mapping[$key] ?? '');
        }
        foreach (['unit', 'role', 'person', 'condition'] as $name) {
            $field = $mapping['fields'][$name] ?? [];
            $mode = isset($field['constant']) ? 'constant' : (isset($field['column']) ? 'column' : ($field === [] && $name === 'condition' ? 'none' : 'selector'));
            $this->fields[$name] = ['mode' => $mode, 'value' => (string) ($field[$mode] ?? ''), 'scope' => $field['scope'] ?? 'record'];
        }
        $this->conflict = $this->dirty = false;
        $this->reason = $this->notice = '';
        $this->resetValidation();
    }

    public function reloadSource(): void
    {
        if ($this->sourceId !== null) {
            $this->selectSource($this->sourceId);
        }
    }

    public function selectCapture(int $id): void
    {
        abort_unless($this->sourceId !== null, 422);
        PepDirectoryCapture::query()->where('source_id', $this->selectedSource->id)->findOrFail($id);
        $this->captureId = $id;
        $this->dirty = true;
        $this->notice = '';
    }

    public function updated(string $property): void
    {
        if (in_array(explode('.', $property)[0], ['directory', 'record', 'section', 'columns', 'fields'], true)) {
            $this->dirty = true;
            $this->notice = '';
        }
    }

    private function mapping(): DirectoryMappingData
    {
        $this->validate(['directory' => 'required|string|max:512', 'record' => 'required|string|max:512',
            'section' => 'nullable|string|max:512', 'columns' => 'nullable|integer|min:1|max:32',
            'fields' => 'required|array:unit,role,person,condition', 'fields.*' => 'array:mode,value,scope',
            'fields.*.mode' => 'required|in:selector,column,constant,none',
            'fields.*.value' => 'present|string|max:512', 'fields.*.scope' => 'required|in:record,directory,section']);
        $mapping = ['directory' => $this->directory, 'record' => $this->record, 'fields' => []];
        if ($this->section !== '') {
            $mapping['section'] = $this->section;
        }
        if ($this->columns !== '') {
            $mapping['columns'] = (int) $this->columns;
        }
        foreach ($this->fields as $name => $field) {
            if ($name === 'condition' && $field['mode'] === 'none') {
                continue;
            }
            if ($field['mode'] === 'column' && preg_match('/^[0-9]+$/D', $field['value']) !== 1) {
                throw ValidationException::withMessages(['mapping' => 'La columna debe ser un índice entero desde 0.']);
            }
            $mapping['fields'][$name] = $field['mode'] === 'selector'
                ? ['selector' => $field['value'], 'scope' => $field['scope']]
                : [$field['mode'] => $field['mode'] === 'column' ? (int) $field['value'] : $field['value']];
        }
        return DirectoryMappingData::fromArray($mapping);
    }

    public function publish(): void
    {
        $this->mutate('publish');
    }

    public function approve(): void
    {
        $this->mutate('approve');
    }

    public function revoke(): void
    {
        $this->mutate('revoke');
    }

    private function mutate(string $action): void
    {
        abort_unless($this->canManage, 403); // Service independently reloads persisted authorization.
        abort_unless($this->sourceId !== null && $this->displayedVersion !== null, 422);
        if ($this->conflict) {
            return; // Keep the displayed version; require explicit reload and renewed reason.
        }
        $decision = ConfigurationDecisionData::fromArray(['expected_version' => $this->displayedVersion, 'reason' => $this->reason]);
        $this->resetValidation();
        try {
            if ($action === 'publish') {
                abort_unless($this->captureId !== null, 422);
                PepDirectoryCapture::query()->where('source_id', $this->selectedSource->id)->findOrFail($this->captureId);
                if (! $this->canGenerate) {
                    throw new PreviewCompilationException();
                }
                $preview = app(DirectoryPreviewService::class)->publishDraftPreview($this->sourceId, $this->captureId, $this->mapping(), $this->actor, $decision);
                $this->previewId = $preview->id;
                $this->displayedVersion = $preview->eligibility_version;
                $this->dirty = false;
                $this->notice = 'Vista previa almacenada. Confirmación pendiente.';
            } else {
                if ($action === 'approve' && $this->dirty) {
                    throw ValidationException::withMessages(['mapping' => 'Previsualice las correcciones antes de confirmar.']);
                }
                abort_if($action === 'approve' && $this->previewId === null, 422);
                $service = app(ConfigurationService::class);
                $receipt = $action === 'approve'
                    ? $service->approve($this->sourceId, $this->previewId, $this->actor, $decision)
                    : $service->revoke($this->sourceId, $this->actor, $decision);
                $this->displayedVersion = $receipt->sequence;
                if ($action === 'revoke') {
                    $this->previewId = null;
                }
                $this->notice = $action === 'approve' ? 'Confirmación registrada. Detección no activada.' : 'Confirmación revocada. Detección no activada.';
            }
            $this->reason = '';
            unset($this->selectedSource, $this->preview);
        } catch (ConfigurationConflictException) {
            $this->conflict = true;
            $this->notice = '';
        } catch (PreviewCompilationException) {
            $this->addError('preview', 'No se pudo generar la vista previa. El estado almacenado no cambió.');
        }
    }

    public function render(): View
    {
        return view('livewire.pep.configuracion-directorio')->layout('layouts.app', ['title' => 'Configuración de directorios']);
    }
}
