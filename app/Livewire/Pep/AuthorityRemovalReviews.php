<?php

declare(strict_types=1);

namespace App\Livewire\Pep;

use App\Models\AuthorityRemovalReview;
use App\Models\Fuente;
use App\Services\AuthorityRemovalReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Revisión de remociones de autoridades'])]
final class AuthorityRemovalReviews extends Component
{
    use WithPagination;

    #[Url]
    public string $estado = 'pending';

    #[Url]
    public string $fuente = '';

    public function mount(): void
    {
        abort_unless(
            (bool) auth()->user()?->hasRole('admin')
                && (bool) auth()->user()?->can('resolver remociones autoridades'),
            403,
        );
    }

    public function updatingEstado(): void
    {
        $this->resetPage();
    }

    public function updatingFuente(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function reviews(): LengthAwarePaginator
    {
        return AuthorityRemovalReview::query()
            ->with(['fuente:id,nombre,organismo,url', 'decididoPor:id,name', 'cambioConfirmado:id'])
            ->when($this->estado !== '', fn (Builder $query): Builder => $query->where('estado', $this->estado))
            ->when($this->fuente !== '', fn (Builder $query): Builder => $query->where('fuente_id', $this->fuente))
            ->latest('created_at')
            ->paginate(20);
    }

    #[Computed]
    public function fuentes(): Collection
    {
        return Fuente::query()
            ->whereHas('revisionesRemocionAutoridades')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'organismo']);
    }

    public function confirm(int $id, AuthorityRemovalReviewService $service): void
    {
        $this->authorizeAction();
        $service->confirm($id, auth()->user(), ['channel' => 'livewire']);
        unset($this->reviews);
        $this->dispatch('notify', mensaje: 'Remoción confirmada y enviada a análisis.', tipo: 'success');
    }

    public function reject(int $id, AuthorityRemovalReviewService $service): void
    {
        $this->authorizeAction();
        $service->reject($id, auth()->user(), ['channel' => 'livewire']);
        unset($this->reviews);
        $this->dispatch('notify', mensaje: 'Observación rechazada.', tipo: 'success');
    }

    public function render(): View
    {
        return view('livewire.pep.authority-removal-reviews', [
            'reviews' => $this->reviews,
            'fuentes' => $this->fuentes,
        ]);
    }

    private function authorizeAction(): void
    {
        abort_unless(
            (bool) auth()->user()?->hasRole('admin')
                && (bool) auth()->user()?->can('resolver remociones autoridades'),
            403,
        );
    }
}
