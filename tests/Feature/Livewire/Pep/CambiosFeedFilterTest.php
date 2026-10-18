<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Pep;

use App\Enums\CambioFeedStatus;
use App\Livewire\Pep\Cambios;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class CambiosFeedFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Fuente $fuente;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.enabled' => false]);
        $this->user = User::factory()->create(['activo' => true]);
        $this->fuente = Fuente::factory()->create();
    }

    public function test_default_filter_shows_review_queue(): void
    {
        $primary = $this->makeCambio(CambioFeedStatus::Primary, $this->payload());
        $review = $this->makeCambio(CambioFeedStatus::Review, null, [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Ana Pérez', 'riesgo' => 'alto'],
        ]);
        $suppressed = $this->makeCambio(CambioFeedStatus::Suppressed, null);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertSet('feed', 'review')
            ->assertSee('Pendientes de revisión (vista inicial)')
            ->assertSee('Vista inicial: cambios amplios o inciertos pendientes de revisión')
            ->assertSeeHtml("wire:key=\"cambio-{$review->id}\"")
            ->assertViewHas('cambios', function ($cambios) use ($primary, $review, $suppressed): bool {
                $ids = $cambios->pluck('id');

                return $ids->contains($review->id)
                    && ! $ids->contains($primary->id)
                    && ! $ids->contains($suppressed->id);
            });
    }

    public function test_explicit_primary_filter_only_shows_schema_valid_canonical_rows(): void
    {
        $primary = $this->makeCambio(CambioFeedStatus::Primary, $this->payload());
        $review = $this->makeCambio(CambioFeedStatus::Review, $this->payload());
        $malformedPrimary = $this->makeCambio(CambioFeedStatus::Primary, [
            'version' => 1,
            'events' => [['type' => 'remocion', 'old' => null, 'new' => null]],
        ]);
        $geminiOnly = $this->makeCambio(CambioFeedStatus::Review, null, [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Ana Pérez', 'riesgo' => 'alto'],
        ]);
        $personProxy = $this->makeCambio(CambioFeedStatus::Review, null, ['posibles_peps' => 'Ana Pérez']);
        $editorial = $this->makeCambio(CambioFeedStatus::Review, null, ['diff_texto' => '+ Menú institucional']);

        Livewire::actingAs($this->user)
            ->withQueryParams(['feed' => 'primary'])
            ->test(Cambios::class)
            ->assertSet('feed', 'primary')
            ->assertSee('Feed principal validado')
            ->assertSeeHtml("wire:key=\"cambio-{$primary->id}\"")
            ->assertViewHas('cambios', function ($cambios) use ($primary, $review, $malformedPrimary, $geminiOnly, $personProxy, $editorial): bool {
                $ids = $cambios->pluck('id');

                return $ids->contains($primary->id)
                    && ! $ids->contains($review->id)
                    && ! $ids->contains($malformedPrimary->id)
                    && ! $ids->contains($geminiOnly->id)
                    && ! $ids->contains($personProxy->id)
                    && ! $ids->contains($editorial->id);
            });
    }

    public function test_review_and_all_filters_describe_and_show_their_real_semantics(): void
    {
        $primary = $this->makeCambio(CambioFeedStatus::Primary, $this->payload());
        $review = $this->makeCambio(CambioFeedStatus::Review, null, ['diff_texto' => '+ Cambio amplio']);
        $suppressed = $this->makeCambio(CambioFeedStatus::Suppressed, null);
        $sourceHealth = $this->makeCambio(CambioFeedStatus::SourceHealth, null);

        $component = Livewire::actingAs($this->user)->test(Cambios::class);

        $component->set('feed', 'review')
            ->assertSee('Pendientes de revisión')
            ->assertViewHas('cambios', fn ($cambios): bool => $cambios->pluck('id')->all() === [$review->id]);

        $component->set('feed', 'all')
            ->assertSee('Todos los cambios registrados')
            ->assertViewHas('cambios', fn ($cambios): bool => $cambios->pluck('id')->sort()->values()->all() === collect([
                $primary->id,
                $review->id,
                $suppressed->id,
                $sourceHealth->id,
            ])->sort()->values()->all());
    }

    public function test_legacy_person_filter_url_values_apply_persona_predicates(): void
    {
        $primaryWithPerson = $this->makeCambio(CambioFeedStatus::Primary, $this->payload('primary-person'), [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Ana Pérez', 'persona_removida' => null],
        ]);
        $primaryWithoutPerson = $this->makeCambio(CambioFeedStatus::Primary, $this->payload('primary-without-person'), [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => null, 'persona_removida' => null],
        ]);
        $reviewWithPerson = $this->makeCambio(CambioFeedStatus::Review, null, [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => null, 'persona_removida' => 'Luis Soto'],
        ]);
        $reviewWithoutPerson = $this->makeCambio(CambioFeedStatus::Review, null, [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => null, 'persona_removida' => null],
        ]);

        Livewire::actingAs($this->user)
            ->withQueryParams(['feed' => 'all', 'filtroConPersona' => 'si'])
            ->test(Cambios::class)
            ->assertSet('feed', 'all')
            ->assertSet('filtroConPersona', 'si')
            ->assertViewHas('cambios', fn ($cambios): bool => $cambios->pluck('id')->sort()->values()->all() === collect([
                $primaryWithPerson->id,
                $reviewWithPerson->id,
            ])->sort()->values()->all());

        Livewire::actingAs($this->user)
            ->withQueryParams(['feed' => 'all', 'filtroConPersona' => 'no'])
            ->test(Cambios::class)
            ->assertSet('feed', 'all')
            ->assertSet('filtroConPersona', 'no')
            ->assertViewHas('cambios', fn ($cambios): bool => $cambios->pluck('id')->sort()->values()->all() === collect([
                $primaryWithoutPerson->id,
                $reviewWithoutPerson->id,
            ])->sort()->values()->all());
    }

    public function test_feed_url_filter_is_independent_from_legacy_person_filter(): void
    {
        $primaryWithPerson = $this->makeCambio(CambioFeedStatus::Primary, $this->payload(), [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Ana Pérez', 'persona_removida' => null],
        ]);
        $reviewWithPerson = $this->makeCambio(CambioFeedStatus::Review, null, [
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Luis Soto', 'persona_removida' => null],
        ]);

        Livewire::actingAs($this->user)
            ->withQueryParams(['feed' => 'review', 'filtroConPersona' => 'si'])
            ->test(Cambios::class)
            ->assertSet('feed', 'review')
            ->assertSet('filtroConPersona', 'si')
            ->assertViewHas('cambios', fn ($cambios): bool => $cambios->pluck('id')->all() === [$reviewWithPerson->id])
            ->assertViewHas('cambios', fn ($cambios): bool => ! $cambios->pluck('id')->contains($primaryWithPerson->id));
    }

    public function test_changing_feed_resets_pagination(): void
    {
        for ($index = 0; $index < 25; $index++) {
            $this->makeCambio(CambioFeedStatus::Review, $this->payload((string) $index));
        }

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->call('gotoPage', 2)
            ->set('feed', 'primary')
            ->assertSet('paginators', ['page' => 1]);
    }

    public function test_authenticated_active_user_without_review_permission_cannot_mark_cambio_as_reviewed(): void
    {
        $cambio = $this->makeCambio(CambioFeedStatus::Primary, $this->payload(), ['revisado' => false]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->call('marcarRevisado', $cambio->id)
            ->assertForbidden();

        $this->assertFalse((bool) $cambio->fresh()->revisado);
    }

    public function test_user_with_review_permission_can_mark_cambio_as_reviewed(): void
    {
        $authorizedUser = $this->makeUserWithReviewPermission();
        $cambio = $this->makeCambio(CambioFeedStatus::Primary, $this->payload(), ['revisado' => false]);

        Livewire::actingAs($authorizedUser)
            ->test(Cambios::class)
            ->call('marcarRevisado', $cambio->id)
            ->assertOk();

        $this->assertTrue((bool) $cambio->fresh()->revisado);
    }

    public function test_review_action_visibility_matches_review_permission(): void
    {
        $authorizedUser = $this->makeUserWithReviewPermission();
        $this->makeCambio(CambioFeedStatus::Review, null, ['revisado' => false]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertDontSee('Marcar revisado');

        Livewire::actingAs($authorizedUser)
            ->test(Cambios::class)
            ->assertSee('Marcar revisado');
    }

    private function makeUserWithReviewPermission(): User
    {
        $permission = Permission::firstOrCreate([
            'name' => 'marcar revisado pep',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create(['activo' => true]);
        $user->givePermissionTo($permission);

        return $user;
    }

    /** @param array<string, scalar|array|null> $overrides */
    private function makeCambio(CambioFeedStatus $status, ?array $payload, array $overrides = []): Cambio
    {
        return Cambio::withoutEvents(fn (): Cambio => Cambio::create(array_merge([
            'fuente_id' => $this->fuente->id,
            'fecha' => now(),
            'hash_anterior' => fake()->sha256(),
            'hash_nuevo' => fake()->sha256(),
            'feed_status' => $status,
            'autoridades_eventos_json' => $payload,
        ], $overrides)));
    }

    /** @return array{version:int,events:array<int,array<string,array<string,string>|string|null>>} */
    private function payload(string $suffix = ''): array
    {
        return ['version' => 1, 'events' => [[
            'type' => 'designacion',
            'old' => null,
            'new' => ['cargo' => 'Directora', 'persona' => 'Ana Pérez'.$suffix],
        ]]];
    }
}
