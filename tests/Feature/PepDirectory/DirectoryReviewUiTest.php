<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

use App\Livewire\Pep\Directorio;
use App\Models\PepDirectoryEvent;
use App\Services\PepDirectory\DTOs\ReviewDecisionData;
use App\Services\PepDirectory\ReviewService;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\PepDirectoryLivewireTestCase;

require_once __DIR__.'/../../Support/GuardsDisposableDatabase.php';
require_once __DIR__.'/../../Support/PepDirectoryReviewTestCase.php';
require_once __DIR__.'/../../Support/PepDirectoryLivewireKernel.php';
require_once __DIR__.'/../../Support/PepDirectoryLivewireTestCase.php';

final class DirectoryReviewUiTest extends PepDirectoryLivewireTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('pep_directory', is_file('/work/config/pep_directory.php') ? require '/work/config/pep_directory.php' : ['ui_enabled' => false]);
        $this->withoutVite();
    }

    private function enable(): void
    {
        $this->app['config']->set('pep_directory.ui_enabled', true);
    }

    private function reviewer(): \App\Models\User
    {
        $user = $this->syntheticUser();
        $user->givePermissionTo(Permission::findOrCreate('gestionar fuentes', 'web'));

        return $user;
    }

    /** Only transaction-scoped synthetic evidence; no real organisation schema. */
    private function proposal(): int
    {
        $fuente = $this->db->table('fuentes')->insertGetId(['label' => 'Synthetic source label']);
        $source = $this->db->table('pep_directory_sources')->insertGetId(['identity' => 'synthetic-ui-'.\Illuminate\Support\Str::uuid(), 'fuente_id' => $fuente]);
        $position = static fn (string $person, string $unit = 'Jurídica'): array => ['unit' => $unit, 'role' => 'Directora', 'person' => $person, 'condition' => 'a.i.', 'evidence' => '<script>alert("synthetic")</script>'];
        $before = [$position('Ana'), $position('Beatriz')];
        $after = [$position('Beatriz'), $position('Carla')];
        $ids = [];
        foreach (['baseline', 'compared', 'compared', 'rejected'] as $index => $disposition) {
            $positions = $index === 0 ? $before : $after;
            $positions[] = $position('Otra unidad', ' Jurídica');
            $payload = ['positions' => $positions, 'validity' => $index === 3 ? 'invalid' : 'valid', 'evidence' => 'captura '.$index];
            $ids[] = $this->db->table('pep_directory_observations')->insertGetId([
                'source_id' => $source, 'submission_id' => 'ui-'.$index, 'capture_identity' => 'ui-'.$index,
                'content_sha256' => hash('sha256', 'ui-'.$index), 'provenance' => '{}', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'observed_at' => '2026-10-18T12:00:0'.$index.'-04:00', 'disposition' => $disposition,
                'reasons' => json_encode($index === 3 ? ['incomplete'] : [], JSON_THROW_ON_ERROR),
            ]);
        }
        $this->db->table('pep_directory_sources')->where('id', $source)->update(['baseline_observation_id' => $ids[2], 'version' => 3]);

        return $this->db->table('pep_directory_events')->insertGetId([
            'source_id' => $source, 'previous_observation_id' => $ids[0], 'current_observation_id' => $ids[1], 'event_index' => 0,
            'payload' => json_encode(['unit' => 'Jurídica', 'role' => 'Directora', 'kind' => 'possible_replacement',
                'before_group' => $before, 'after_group' => $after, 'outgoing' => [$before[0]], 'incoming' => [$after[1]],
                'previous_evidence' => '<script>alert("before")</script>', 'current_evidence' => '<img src=x onerror=alert(1)>',
                'detected_at' => '2026-10-18T12:00:01-04:00', 'effective_date' => null], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_disabled_initial_and_hydrated_requests_stop_before_pilot_sql(): void
    {
        self::assertFalse(config('pep_directory.ui_enabled'));
        $user = $this->syntheticUser();
        $queries = 0;
        $armed = true;
        $this->db->beforeExecuting(static function (string $sql) use (&$armed, &$queries): void {
            if ($armed && str_contains($sql, 'pep_directory_')) {
                $queries++;
                throw new \RuntimeException('Disabled UI queried pilot storage.');
            }
        });
        try {
            Livewire::actingAs($user)->test(Directorio::class)->assertStatus(404);
            $this->enable();
            $armed = false;
            $page = Livewire::actingAs($user)->test(Directorio::class)->assertOk();
            $this->app['config']->set('pep_directory.ui_enabled', false);
            $armed = true;
            $page->call('$refresh')->assertStatus(404);
            self::assertSame(0, $queries);
        } finally {
            $armed = false;
        }
    }

    public function test_lifecycle_reloads_active_and_read_permission_on_update(): void
    {
        $this->enable();
        Livewire::test(Directorio::class)->assertStatus(401);
        $user = $this->syntheticUser(allowed: false);
        Livewire::actingAs($user)->test(Directorio::class)->assertStatus(403);
        $user->givePermissionTo(Permission::findOrCreate('ver cambios pep', 'web'));
        $page = Livewire::actingAs($user)->test(Directorio::class)->assertOk();
        $user->revokePermissionTo('ver cambios pep');
        $page->call('$refresh')->assertStatus(403);
        $user->givePermissionTo('ver cambios pep');
        $page = Livewire::actingAs($user)->test(Directorio::class)->assertOk();
        $this->db->table('users')->where('id', $user->id)->update(['activo' => false]);
        self::assertTrue($user->activo);
        $page->call('$refresh')->assertStatus(403);
    }

    public function test_operator_inspects_escaped_groups_history_but_forged_decision_has_no_effect(): void
    {
        $this->enable();
        $id = $this->proposal();
        $user = $this->syntheticUser();
        $user->givePermissionTo(Permission::findOrCreate('marcar revisado pep', 'web'));
        $original = PepDirectoryEvent::findOrFail($id)->getRawOriginal();
        $count = $this->db->table('pep_directory_reviews')->count();
        $page = Livewire::actingAs($user)->test(Directorio::class)->call('selectProposal', $id)
            ->assertSee('Ana')->assertSee('Beatriz')->assertSee('Carla')->assertSee('Personas que dejan de figurar')
            ->assertSee('Personas que aparecen')->assertSee('Fecha efectiva del cambio: desconocida')
            ->assertSee('Últimos titulares observados')->assertSee('2026-10-18T12:00:02-04:00')
            ->assertSee('Rechazada')->assertSee('incomplete')->assertDontSee('Otra unidad')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("before")</script>', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('wire:click="decide', false);
        self::assertMatchesRegularExpression('/Últimos titulares observados.*2026-10-18T12:00:02-04:00.*Beatriz.*Carla.*Rechazada/su', $page->html());
        $page->set('reason', 'Intento operador')->call('decide', 'confirmed')->assertForbidden();
        self::assertSame($count, $this->db->table('pep_directory_reviews')->count());
        self::assertSame($original, PepDirectoryEvent::findOrFail($id)->getRawOriginal());
    }

    public function test_all_decisions_write_through_real_service_with_server_actor_and_explicit_reason(): void
    {
        $this->enable();
        $id = $this->proposal();
        $actor = $this->reviewer();
        $page = Livewire::actingAs($actor)->test(Directorio::class)->call('selectProposal', $id);
        foreach (['confirmed', 'discarded', 'pending'] as $index => $state) {
            if ($index > 0) {
                $page->call('reloadProposal');
            }
            $page->set('reason', str_repeat('á', 10001))->call('decide', $state)->assertHasErrors('reason');
            $page->set('reason', '   ')->call('decide', $state)->assertHasErrors('reason');
            $page->set('reason', 'Revisión humana á '.$state)->call('decide', $state)->assertHasNoErrors();
            $review = $this->db->table('pep_directory_reviews')->where('event_id', $id)->orderByDesc('version')->first();
            self::assertSame($state, $review->new_state);
            self::assertSame($index + 1, $review->version);
            self::assertSame('user:'.$actor->id, $review->actor_identity);
            self::assertSame(['id' => $actor->id, 'name' => $actor->name], json_decode($review->actor_snapshot, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame('Revisión humana á '.$state, $review->reason);
            $page->assertSee($review->actor_identity)->assertSee('Revisión humana á '.$state);
        }
        self::assertSame(3, $this->db->table('pep_directory_reviews')->where('event_id', $id)->count());
        $this->db->table('users')->where('id', $actor->id)->update(['name' => 'Nombre vivo modificado']);
        $page->call('$refresh')->assertSee('Synthetic Harness Actor')->assertSee('Nombre vivo modificado');
        $page->call('reloadProposal');
        $actor->revokePermissionTo('gestionar fuentes');
        $page->set('reason', 'Permiso revocado')->call('decide', 'confirmed')->assertForbidden();
        self::assertSame(3, $this->db->table('pep_directory_reviews')->where('event_id', $id)->count());
    }

    public function test_stale_decision_never_refreshes_version_or_retries_until_explicit_reload(): void
    {
        $this->enable();
        $id = $this->proposal();
        $actor = $this->reviewer();
        $original = PepDirectoryEvent::with('source.observations')->findOrFail($id);
        $baseline = $original->source->getRawOriginal();
        $observations = $original->source->observations->map->getRawOriginal()->all();
        $page = Livewire::actingAs($actor)->test(Directorio::class)->call('selectProposal', $id);
        app(ReviewService::class)->decide($id, $actor, ReviewDecisionData::fromArray([
            'state' => 'discarded', 'expected_version' => 0, 'reason' => 'Otra revisión',
        ]));
        $page->set('reason', 'Mi revisión')->call('decide', 'confirmed')->assertSee('Recargue')
            ->assertSet('displayedVersion', 0)->assertSet('conflict', true);
        $page->call('decide', 'confirmed')->assertSet('displayedVersion', 0);
        self::assertSame(1, $this->db->table('pep_directory_reviews')->where('event_id', $id)->count());
        self::assertSame('discarded', PepDirectoryEvent::findOrFail($id)->review_state);
        self::assertSame($original->payload, PepDirectoryEvent::findOrFail($id)->payload);
        self::assertSame($baseline, $original->source->fresh()->getRawOriginal());
        self::assertSame($observations, $original->source->observations()->get()->map->getRawOriginal()->all());
        $page->call('reloadProposal')->assertSet('displayedVersion', 1)->assertSet('conflict', false)
            ->set('reason', 'Decisión explícita tras recarga')->call('decide', 'confirmed');
        self::assertSame(2, PepDirectoryEvent::findOrFail($id)->review_version);
    }

    public function test_nullable_source_link_and_optional_legacy_payload_render_without_inventing_identity(): void
    {
        $this->enable();
        $id = $this->proposal();
        $source = PepDirectoryEvent::findOrFail($id)->source;
        $this->db->table('pep_directory_sources')->where('id', $source->id)->update(['fuente_id' => null, 'baseline_observation_id' => null]);
        $this->db->table('pep_directory_events')->where('id', $id)->update(['payload' => json_encode([
            'unit' => 'Jurídica', 'role' => 'Directora', 'before' => ['person' => 'Anterior legado'],
        ], JSON_THROW_ON_ERROR)]);
        Livewire::actingAs($this->syntheticUser())->test(Directorio::class)->call('selectProposal', $id)
            ->assertOk()->assertSee($source->identity)->assertSee('Anterior legado')->assertSee('Sucesor desconocido')
            ->assertSee('Sin referencia calificada')->assertSee('Fecha efectiva del cambio: desconocida');
        self::assertSame(1, $this->db->table('pep_directory_events')->where('source_id', $source->id)->count());
    }

    public function test_selected_proposal_is_locked(): void
    {
        $this->enable();
        $id = $this->proposal();
        $page = Livewire::actingAs($this->reviewer())->test(Directorio::class)->call('selectProposal', $id);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('selectedProposal', $id + 1);
    }

    public function test_displayed_version_is_locked(): void
    {
        $this->enable();
        $id = $this->proposal();
        $page = Livewire::actingAs($this->reviewer())->test(Directorio::class)->call('selectProposal', $id);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('displayedVersion', 99);
    }

    public function test_filters_validate_reset_and_paginators_are_independent(): void
    {
        $this->enable();
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->proposal();
        }
        $source = PepDirectoryEvent::findOrFail($ids[0])->source_id;
        $observation = (array) $this->db->table('pep_directory_observations')->where('source_id', $source)->first();
        unset($observation['id']);
        for ($i = 0; $i < 11; $i++) {
            $observation['submission_id'] = 'pagination-'.$i;
            $observation['capture_identity'] = 'pagination-'.$i;
            $observation['payload'] = json_encode(['positions' => [['unit' => 'Jurídica', 'role' => 'Directora', 'person' => 'Titular paginado']], 'evidence' => 'historia adicional '.$i], JSON_THROW_ON_ERROR);
            $this->db->table('pep_directory_observations')->insert($observation);
        }
        $page = Livewire::actingAs($this->syntheticUser())->test(Directorio::class)
            ->assertSee('wire:key="proposal-'.$ids[11].'"', false)->assertDontSee('wire:key="proposal-'.$ids[0].'"', false)
            ->call('setPage', 2)->assertSet('paginators.page', 2)->assertSee('wire:key="proposal-'.$ids[0].'"', false)
            ->assertDontSee('wire:key="proposal-'.$ids[11].'"', false)
            ->set('state', 'confirmed')->assertSet('paginators.page', 1)->assertSee('Sin propuestas')
            ->set('state', 'invalid')->assertHasErrors('state')
            ->set('state', '')->set('source', (string) $source)->assertSee('Jurídica')
            ->assertSee('wire:key="proposal-'.$ids[0].'"', false)->assertDontSee('wire:key="proposal-'.$ids[11].'"', false)
            ->set('source', 'bad')->assertHasErrors('source')
            ->set('source', str_repeat('9', 30))->assertHasErrors('source')->set('source', '')
            ->call('selectProposal', $ids[0])->assertSee('historia adicional 10')->assertDontSee('captura 0')
            ->call('setPage', 2, 'historyPage')->assertSet('paginators.page', 1)
            ->assertSet('paginators.historyPage', 2)->assertSee('captura 0')->assertDontSee('historia adicional 10');
        self::assertNotSame($ids[0], $ids[11]);
    }

    /** Load actual route definitions without application bootstrap. */
    private function productionRoutes(string $path): void
    {
        $this->app['router']->aliasMiddleware('usuario.activo', \App\Http\Middleware\UsuarioActivo::class);
        $this->app['router']->middleware('web')->group($path);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function routeContract(): array
    {
        $result = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (! in_array($route->getName(), ['pep.directorio', 'pep.directorio.configuracion'], true)) {
                $result[] = [$route->getName(), $route->methods(), $route->uri(), $route->gatherMiddleware()];
            }
        }

        return $result;
    }

    public function test_actual_production_route_http_middleware_and_default_off_baseline_differential(): void
    {
        $base = getenv('PEP_UI_BASE_DIR');
        self::assertIsString($base, 'Positive proof requires the immutable parent export.');
        $this->withMiddleware();
        $frameworkRoutes = clone $this->app['router']->getRoutes();
        $this->productionRoutes($base.'/routes/web.php');
        $oldRoutes = $this->routeContract();
        $user = $this->syntheticUser();
        $this->actingAs($user);
        $slot = new HtmlString($this->legacyOutput());
        Livewire::flushState();
        $old = view()->file($base.'/resources/views/layouts/app.blade.php', ['slot' => $slot, 'title' => 'Prueba'])->render();
        $this->app['router']->setRoutes($frameworkRoutes);
        $this->productionRoutes('/work/routes/web.php');
        self::assertSame($oldRoutes, $this->routeContract());
        Livewire::flushState();
        $candidateSlot = new HtmlString($this->legacyOutput());
        self::assertSame($this->stableMarkup((string) $slot), $this->stableMarkup((string) $candidateSlot));
        $candidate = view('layouts.app', ['slot' => $candidateSlot, 'title' => 'Prueba'])->render();
        self::assertSame($this->publicLinks($old), $this->publicLinks($candidate));
        self::assertSame($this->stableMarkup($old), $this->stableMarkup($candidate));
        self::assertStringNotContainsString('/pep/directorio', $candidate);
        // Middleware-only requests precede component aborts in this single minimal Application.
        $inactive = $this->syntheticUser(active: false);
        $this->authenticateSession($inactive);
        $this->get('/pep/directorio')->assertRedirect(route('login'));
        $this->getJson('/pep/directorio')->assertUnauthorized();
        $this->authenticateSession($user);
        $this->get('/pep/directorio')->assertNotFound();
        $this->enable();
        $this->authenticateSession($user);
        $this->get('/pep/directorio')->assertOk()->assertSee('Directorio PEP');
        $enabled = view('layouts.app', ['slot' => $slot])->render();
        self::assertContains('/pep/directorio', $this->publicLinks($enabled));
        self::assertContains('/pep/directorio/configuracion', $this->publicLinks($enabled));
        self::assertSame($this->publicLinks($old), array_values(array_filter($this->publicLinks($enabled),
            static fn (string $link): bool => ! in_array($link, ['/pep/directorio', '/pep/directorio/configuracion'], true))));
        $denied = $this->syntheticUser(allowed: false);
        $this->authenticateSession($denied);
        $this->getJson('/pep/directorio')->assertForbidden();
        $this->actingAs($denied);
        self::assertNotContains('/pep/directorio', $this->publicLinks(view('layouts.app', ['slot' => $slot])->render()));
        self::assertFalse($this->committedAuthorization);
        self::assertSame(1, $this->db->transactionLevel());
    }

    private function legacyOutput(): string
    {
        // Render the unchanged legacy Blade with unsaved models; never query cambios/production tables.
        $this->app->register(\Illuminate\Pagination\PaginationServiceProvider::class);
        $fuente = new \App\Models\Fuente(['nombre' => 'Fuente sintética', 'url' => 'https://example.invalid/directorio']);
        $cambio = new \App\Models\Cambio(['fecha' => '2026-10-18T12:00:00Z', 'revisado' => false,
            'lineas_nuevas' => 2, 'lineas_quitadas' => 1, 'gemini_analyzed' => false]);
        $cambio->id = 900001;
        $cambio->setRelation('fuente', $fuente);
        $page = new \Illuminate\Pagination\LengthAwarePaginator([$cambio], 2, 1, 1, ['path' => '/pep/cambios']);
        // Bare Blade rendering does not boot Livewire's pagination component hook.
        $oldView = \Illuminate\Pagination\Paginator::$defaultView;
        $oldSimpleView = \Illuminate\Pagination\Paginator::$defaultSimpleView;
        $theme = config('livewire.pagination_theme', 'tailwind');
        try {
            \Illuminate\Pagination\Paginator::defaultView('livewire::'.$theme);
            \Illuminate\Pagination\Paginator::defaultSimpleView('livewire::simple-'.$theme);
            $html = view('livewire.pep.cambios', ['fuentes' => collect([$fuente]), 'cambios' => $page,
                'filtroConPersona' => 'si', 'verDiffId' => null])->render();
        } finally {
            \Illuminate\Pagination\Paginator::defaultView($oldView);
            \Illuminate\Pagination\Paginator::defaultSimpleView($oldSimpleView);
        }
        self::assertSame($oldView, \Illuminate\Pagination\Paginator::$defaultView);
        self::assertSame($oldSimpleView, \Illuminate\Pagination\Paginator::$defaultSimpleView);
        self::assertStringContainsString('wire:model.live="filtroFuente"', $html);
        self::assertStringContainsString('wire:model.live="filtroRevisado"', $html);
        self::assertStringContainsString('wire:model.live="filtroRiesgo"', $html);
        self::assertStringContainsString('Marcar revisado', $html);
        self::assertStringContainsString('nextPage(\'page\')', $html);
        self::assertStringContainsString('https://example.invalid/directorio', $html);

        return $html;
    }

    private function publicLinks(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $matches);

        return array_map(static fn (string $url): string => str_replace('http://localhost', '', html_entity_decode($url)), $matches[1]);
    }

    private function stableMarkup(string $html): string
    {
        // Ignore only Livewire's inert conditional morph markers and whitespace.
        $html = str_replace(['<!--[if BLOCK]><![endif]-->', '<!--[if ENDBLOCK]><![endif]-->'], '', $html);

        return preg_replace('/\s+/', ' ', trim($html));
    }
}
