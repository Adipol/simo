<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

use App\Models\PepDirectoryEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Support\PepDirectoryLivewireTestCase;

require_once __DIR__.'/../../Support/GuardsDisposableDatabase.php';
require_once __DIR__.'/../../Support/PepDirectoryReviewTestCase.php';
require_once __DIR__.'/../../Support/PepDirectoryLivewireKernel.php';
require_once __DIR__.'/../../Support/PepDirectoryLivewireTestCase.php';

/** A probe, NOT the future directory component or its authorization contract. */
final class DirectoryHarnessProbe extends Component
{
    public string $name = '';
    public string $saved = '';

    public function mount(bool $enabled = true): void
    {
        abort_unless($enabled, 404);
        PepDirectoryEvent::query()->limit(1)->get();
    }

    public function save(): void
    {
        $this->validate(['name' => 'required|string|min:3']);
        $this->saved = $this->name;
    }

    public function render(): string
    {
        return '<div>Harness probe <input wire:model="name"><button wire:click="save">Save</button><span>{{ $saved }}</span></div>';
    }
}

final class LivewireHarnessTest extends PepDirectoryLivewireTestCase
{
    public function test_real_livewire_render_update_action_and_validation(): void
    {
        Livewire::test(DirectoryHarnessProbe::class)
            ->assertStatus(200)->assertSee('Harness probe')->assertSee('wire:snapshot', false)
            ->set('name', '')->call('save')->assertHasErrors(['name' => 'required'])
            ->set('name', 'AB')->call('save')->assertHasErrors(['name' => 'min'])
            ->set('name', 'Synthetic Value')->call('save')->assertHasNoErrors()
            ->assertSet('saved', 'Synthetic Value')->assertSee('Synthetic Value');
        self::assertFalse($this->app->shouldSkipMiddleware());
        $this->assertIsolatedBootstrap();
    }

    public function test_disabled_probe_aborts_before_any_pilot_model_query(): void
    {
        $queries = 0;
        $armed = true;
        // beforeExecuting runs BEFORE PDO, not the post-query QueryExecuted event.
        $this->db->beforeExecuting(static function (string $sql) use (&$queries, &$armed): void {
            if ($armed && str_contains($sql, 'pep_directory_')) {
                $queries++;
                throw new \RuntimeException('Disabled probe attempted a pilot query.');
            }
        });
        try {
            Livewire::test(DirectoryHarnessProbe::class, ['enabled' => false])->assertStatus(404);
        } finally {
            $armed = false; // Preservation reads must remain available even on assertion failure.
        }
        self::assertSame(0, $queries);
        $this->assertIsolatedBootstrap();
    }

    public function test_real_http_middleware_uses_current_session_identity_and_permission(): void
    {
        $this->withMiddleware(); // Livewire::test middleware bypass is NOT authorization proof.
        $router = $this->app['router'];
        $router->get('/harness/login', static fn (): string => 'Harness login')->name('login');
        $router->get('/harness/protected', static fn (Request $request): array => [
            'actor' => $request->user()?->getKey(), 'active' => $request->user()?->activo,
        ])->middleware(['web', 'auth', 'activo', 'permission:ver cambios pep']);
        $router->getRoutes()->refreshNameLookups();

        $this->getJson('/harness/protected')->assertUnauthorized();
        $user = $this->syntheticUser(allowed: false);
        $this->authenticateSession($user);
        $this->getJson('/harness/protected')->assertForbidden();

        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('ver cambios pep', 'web'));
        $this->authenticateSession($user);
        $this->getJson('/harness/protected')->assertOk()->assertExactJson(['actor' => $user->getKey(), 'active' => true]);

        // A previously valid object/session must not conceal persisted deactivation.
        $this->db->table('users')->where('id', $user->getKey())->update(['activo' => false]);
        self::assertTrue($user->activo);
        $this->authenticateSession($user);
        $this->get('/harness/protected')->assertRedirect('/harness/login');
        self::assertGuest();
        $this->assertIsolatedBootstrap();
    }

    public function test_complete_existing_layout_renders_with_test_named_routes_and_vite_stub(): void
    {
        $this->withMiddleware();
        $this->withoutVite(); // No build/manifest/dev server access.
        foreach (['dashboard', 'scraper.resultados', 'pep.cambios', 'gaceta.eventos', 'gaceta.normas',
            'gaceta.personas', 'scraper.sitios', 'pep.fuentes', 'scraper.familias-lemas', 'scraper.cargos-pep',
            'scraper.entidades-publicas', 'configuracion.paises', 'scripts.estado', 'scripts.configuracion',
            'usuarios.index', 'logout', 'login'] as $name) {
            $this->app['router']->get('/harness/fixture/'.$name, static fn (): string => 'Fixture')->name($name);
        }
        $user = $this->syntheticUser();
        $this->authenticateSession($user);
        $this->app['router']->get('/harness/layout', static fn (): View => view('layouts.app', [
            'title' => 'Harness layout', 'slot' => new HtmlString('<p>synthetic-slot</p>'),
        ]))->middleware(['web', 'auth', 'activo', 'permission:ver cambios pep']);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->get('/harness/layout')->assertOk()->assertSee('synthetic-slot')
            ->assertSee('Synthetic Harness Actor')->assertSee('Cambios PEP')->assertSee('Resultados scraper')
            ->assertSee('/harness/fixture/pep.cambios', false)->assertSee('wire', false)
            ->assertSee('</html>', false);
        self::assertSame(['/work/resources/views'], $this->app['config']->get('view.paths'));
        self::assertStringStartsWith('/tmp/pep-review/views/', Blade::getCompiledPath('probe'));
        $this->assertIsolatedBootstrap();
    }

    private function assertIsolatedBootstrap(): void
    {
        self::assertTrue($this->app->environment('testing'));
        self::assertSame('/tmp/pep-review/never-load.env', $this->app->environmentFilePath());
        self::assertSame('/tmp/pep-review/APP_CONFIG_CACHE.php', $this->app->getCachedConfigPath());
        self::assertFalse($this->committedAuthorization);
        self::assertSame(1, $this->db->transactionLevel());
        $included = array_map(static fn (string $path): string => str_replace('\\', '/', $path), get_included_files());
        foreach (['/work/bootstrap/app.php', '/work/bootstrap/providers.php', '/work/routes/web.php',
            '/work/.env', '/work/bootstrap/cache/config.php'] as $forbidden) {
            self::assertNotContains($forbidden, $included);
        }
        foreach (array_keys($this->app->getLoadedProviders()) as $provider) {
            self::assertStringStartsNotWith('App\\Providers\\', $provider);
        }
    }
}
