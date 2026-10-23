<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Exceptions\PepDirectory\ReviewConflictException;
use App\Models\PepDirectoryEvent;
use App\Models\PepDirectoryObservation;
use App\Models\PepDirectorySource;
use App\Models\User;
use App\Services\PepDirectory\DTOs\ReviewDecisionData;
use App\Services\PepDirectory\ReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\PermissionRegistrar;

final class Directorio extends Component
{
    use WithPagination;

    #[Url]
    public string $state = '';

    #[Url]
    public string $source = '';

    #[Locked]
    public ?int $selectedProposal = null;

    #[Locked]
    public ?int $displayedVersion = null;

    #[Locked]
    public bool $conflict = false;

    #[Locked]
    public bool $decisionSaved = false;

    public string $reason = '';

    protected User $actor;

    public function boot(): void
    {
        // This runs on initial AND hydrated requests, before any pilot read.
        abort_unless(config('pep_directory.ui_enabled', false) === true, 404);
        abort_unless(auth()->id() !== null, 401);
        $actor = User::query()->find(auth()->id());
        abort_unless($actor?->activo, 403);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        abort_unless($actor->checkPermissionTo('ver cambios pep'), 403);
        $this->actor = $actor;
    }

    public function mount(): void
    {
        $this->validateFilters();
    }

    public function updatedState(): void
    {
        $this->resetPage();
        $this->validateFilters();
    }

    public function updatedSource(): void
    {
        $this->resetPage();
        $this->validateFilters();
    }

    private function validateFilters(): void
    {
        $this->validate(['state' => 'nullable|in:pending,confirmed,discarded', 'source' => ['nullable', 'integer', 'min:1', 'regex:/^[1-9][0-9]*$/D']]);
    }

    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        // Invalid hydrated filters must not become query fragments; validation errors stay visible.
        $valid = in_array($this->state, ['', 'pending', 'confirmed', 'discarded'], true)
            && ($this->source === '' || (preg_match('/^[1-9][0-9]*$/D', $this->source) === 1
                && filter_var($this->source, FILTER_VALIDATE_INT) !== false));

        return PepDirectoryEvent::query()->with(['source.fuente', 'currentObservation'])
            ->when(! $valid, fn (Builder $query): Builder => $query->whereKey(-1))
            ->when($valid && $this->state !== '', fn (Builder $query): Builder => $query->where('review_state', $this->state))
            ->when($valid && $this->source !== '', fn (Builder $query): Builder => $query->where('source_id', $this->source))
            ->orderByDesc('id')->paginate(10);
    }

    #[Computed]
    public function sources(): Collection
    {
        return PepDirectorySource::with('fuente')->orderBy('id')->get();
    }

    #[Computed]
    public function proposal(): ?PepDirectoryEvent
    {
        return $this->selectedProposal === null ? null : PepDirectoryEvent::withReviewEvidence()
            ->with(['source.fuente', 'source.baselineObservation'])->findOrFail($this->selectedProposal);
    }

    public function selectProposal(int $eventId): void
    {
        $event = PepDirectoryEvent::query()->findOrFail($eventId);
        $this->selectedProposal = $event->id;
        $this->displayedVersion = $event->review_version;
        $this->conflict = false;
        $this->decisionSaved = false;
        $this->reason = '';
        $this->resetValidation();
        $this->resetPage('historyPage');
        unset($this->proposal, $this->history);
    }

    public function reloadProposal(): void
    {
        if ($this->selectedProposal !== null) {
            $this->selectProposal($this->selectedProposal);
        }
    }

    #[Computed]
    public function canReview(): bool
    {
        return $this->actor->checkPermissionTo('gestionar fuentes');
    }

    public function decide(string $state): void
    {
        // The service independently reloads persisted active/gestionar fuentes on EVERY mutation.
        abort_unless($this->canReview, 403);
        abort_unless($this->selectedProposal !== null && $this->displayedVersion !== null, 422);
        if ($this->conflict || $this->decisionSaved) {
            return; // Explicit reload and a new decision are required; never retry automatically.
        }
        $decision = ReviewDecisionData::fromArray([
            'state' => $state, 'expected_version' => $this->displayedVersion, 'reason' => $this->reason,
        ]);
        try {
            app(ReviewService::class)->decide($this->selectedProposal, auth()->user(), $decision);
            $this->decisionSaved = true;
            $this->resetValidation();
            unset($this->proposal, $this->proposals);
        } catch (ReviewConflictException) {
            $this->conflict = true;
        }
    }

    #[Computed]
    public function history(): ?LengthAwarePaginator
    {
        $event = $this->proposal;
        if ($event === null) {
            return null;
        }

        // Source observations, including unchanged and rejected captures, not just reviewed events.
        return $event->source->observations()
            ->where(function (Builder $query) use ($event): void {
                $query->whereJsonContains('payload->positions', [[
                    'unit' => $event->payload['unit'] ?? null, 'role' => $event->payload['role'] ?? null,
                ]])->orWhere('disposition', 'rejected');
            })->reorder()->orderByDesc('id')->paginate(10, ['*'], 'historyPage');
    }

    /** Exact wire identity, matching core grouping: no trim/case folding/fuzzy matching. */
    public function holders(?PepDirectoryObservation $observation): array
    {
        $payload = $this->proposal?->payload ?? [];

        return array_values(array_filter($observation?->payload['positions'] ?? [], static fn (array $position): bool =>
            ($position['unit'] ?? null) === ($payload['unit'] ?? null)
            && ($position['role'] ?? null) === ($payload['role'] ?? null)));
    }

    public function institution(PepDirectorySource $source): string
    {
        // Legacy synthetic fuentes have only id/label; never query an organisation table.
        return (string) ($source->fuente?->getAttribute('organismo') ?: $source->identity);
    }

    public function stateLabel(string $state): string
    {
        return ['pending' => 'Pendiente', 'confirmed' => 'Confirmado', 'discarded' => 'Descartado'][$state] ?? $state;
    }

    public function render(): View
    {
        return view('livewire.pep.directorio')->layout('layouts.app', ['title' => 'Directorio PEP']);
    }
}
