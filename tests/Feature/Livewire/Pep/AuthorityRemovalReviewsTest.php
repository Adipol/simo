<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Pep;

use App\Enums\AuthorityRemovalReviewStatus;
use App\Livewire\Pep\AuthorityRemovalReviews;
use App\Models\AuthorityRemovalReview;
use App\Models\Fuente;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\AuthorityRemovalReviewService;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class AuthorityRemovalReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_non_admin_roles_are_forbidden(): void
    {
        $this->seed(RolesPermisosSeeder::class);
        $this->get(route('pep.authority-removal-reviews'))->assertRedirect(route('login'));

        foreach (['operador', 'supervisor'] as $role) {
            $user = User::factory()->create(['activo' => true]);
            $user->assignRole($role);
            $this->actingAs($user)->get(route('pep.authority-removal-reviews'))->assertForbidden();
        }
    }

    public function test_admin_filters_reviews_and_pagination_resets(): void
    {
        $admin = $this->admin();
        $first = $this->review();
        $second = $this->review(AuthorityRemovalReviewStatus::Rejected);

        Livewire::actingAs($admin)
            ->test(AuthorityRemovalReviews::class)
            ->assertViewHas('reviews', fn ($reviews): bool => $reviews->pluck('id')->contains($first->id) && ! $reviews->pluck('id')->contains($second->id))
            ->call('gotoPage', 2)
            ->set('fuente', (string) $first->fuente_id)
            ->assertSet('paginators', ['page' => 1])
            ->assertViewHas('reviews', fn ($reviews): bool => $reviews->every(fn (AuthorityRemovalReview $review): bool => $review->fuente_id === $first->fuente_id))
            ->set('estado', 'rejected')
            ->assertSet('paginators', ['page' => 1])
            ->set('fuente', '')
            ->assertViewHas('reviews', fn ($reviews): bool => $reviews->pluck('id')->contains($second->id));
    }

    public function test_admin_can_confirm_and_resolved_row_hides_actions(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $review = $this->review();

        Livewire::actingAs($admin)
            ->test(AuthorityRemovalReviews::class)
            ->call('confirm', $review->id)
            ->set('estado', 'confirmed')
            ->assertDontSeeHtml("wire:click=\"confirm({$review->id})\"")
            ->assertDontSeeHtml("wire:click=\"reject({$review->id})\"");

        $this->assertSame(AuthorityRemovalReviewStatus::Confirmed, $review->fresh()->estado);
    }

    public function test_admin_can_reject_without_creating_cambio(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $review = $this->review();

        Livewire::actingAs($admin)
            ->test(AuthorityRemovalReviews::class)
            ->call('reject', $review->id);

        $this->assertSame(AuthorityRemovalReviewStatus::Rejected, $review->fresh()->estado);
        $this->assertDatabaseCount('cambios', 0);
    }

    private function admin(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function review(AuthorityRemovalReviewStatus $status = AuthorityRemovalReviewStatus::Pending): AuthorityRemovalReview
    {
        $fuente = Fuente::factory()->create();
        $baseline = [
            ['cargo' => 'Director', 'persona' => 'Ana'],
            ['cargo' => 'Auditor', 'persona' => 'Luis'],
        ];
        $candidate = [['cargo' => 'Director', 'persona' => 'Ana']];
        $snapshot = Snapshot::create([
            'fuente_id' => $fuente->id,
            'hash' => str_repeat('b', 64),
            'texto' => 'content',
            'metodo' => 'html_estatico',
            'autoridades_json' => array_merge($baseline, [['_authority_roster' => ['version' => 2, 'pending' => $candidate]]]),
            'fecha' => now(),
        ]);

        return AuthorityRemovalReview::create([
            'fuente_id' => $fuente->id,
            'snapshot_base_id' => $snapshot->id,
            'origen' => 'pep_monitor',
            'linea_base_json' => $baseline,
            'candidato_json' => $candidate,
            'eventos_propuestos_json' => [['type' => 'remocion', 'old' => $baseline[1], 'new' => null]],
            'evidencia_json' => ['version' => 1],
            'fingerprint' => AuthorityRemovalReviewService::fingerprint($fuente->id, $baseline, $candidate),
            'estado' => $status,
            'decidido_at' => $status === AuthorityRemovalReviewStatus::Pending ? null : now(),
        ]);
    }
}
