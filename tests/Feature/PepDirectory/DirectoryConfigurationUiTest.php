<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

require_once __DIR__.'/../../Support/GuardsDisposableDatabase.php';
require_once __DIR__.'/../../Support/PepDirectoryReviewTestCase.php';
require_once __DIR__.'/../../Support/PepDirectoryLivewireKernel.php';
require_once __DIR__.'/../../Support/PepDirectoryLivewireTestCase.php';

use App\Livewire\Pep\ConfiguracionDirectorio;
use App\Models\{PepDirectorySource, User};
use App\Services\PepDirectory\{ConfigurationService, DirectoryPreviewService, PreviewCompiler};
use App\Services\PepDirectory\DTOs\{CompiledPreviewData, ConfigurationDecisionData, DirectoryMappingData, ServerCaptureData};
use Illuminate\Database\Schema\Blueprint;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\PepDirectoryLivewireTestCase;

final class DirectoryConfigurationUiTest extends PepDirectoryLivewireTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('pep_directory', require '/work/config/pep_directory.php');
        $this->withoutVite();
        $this->app['router']->aliasMiddleware('usuario.activo', \App\Http\Middleware\UsuarioActivo::class);
        $this->app['router']->middleware('web')->group('/work/routes/web.php');
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->db)) {
                self::assertNull($this->db->selectOne("SELECT to_regprocedure('public.pep_invalidate_source()') AS name")->name);
                self::assertSame(0, (int) $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_trigger WHERE tgname = 'pep_fuente_invalidate'")->n);
                echo "FUNCTION/TRIGGER ABSENT AFTER ROLLBACK\n";
            }
        }
    }

    private function enable(): void
    {
        try {
            $this->db->getSchemaBuilder()->table('fuentes', function (Blueprint $table): void {
                $table->text('url')->nullable();
                $table->string('tipo', 32)->nullable();
                $table->boolean('activo')->default(true);
            });
            (require '/work/database/migrations/2026_10_18_000002_create_pep_directory_configuration_tables.php')->up();
            $this->app['config']->set('pep_directory.ui_enabled', true);
        } catch (\Throwable $failure) {
            $this->tearDown(); // PHPUnit does not tear down a failed setup/overlay.
            throw $failure;
        }
    }

    private function decision(int $version): ConfigurationDecisionData
    {
        return ConfigurationDecisionData::fromArray(['expected_version' => $version, 'reason' => 'Decisión sintética']);
    }

    private function mapping(): DirectoryMappingData
    {
        return DirectoryMappingData::fromArray(['directory' => 'table', 'record' => 'tr', 'columns' => 2,
            'fields' => ['unit' => ['constant' => 'Synthetic unit'], 'role' => ['column' => 0], 'person' => ['column' => 1]]]);
    }

    /** Same synthetic artifact factory contract as DirectoryPreviewServiceTest; never runs Python. */
    private function compiler(bool $fail = false, ?array $resultPositions = null): PreviewCompiler
    {
        $compiler = $this->createMock(PreviewCompiler::class);
        $compiler->method('compile')->willReturnCallback(function (array $d, string $body, array $metadata) use ($fail, $resultPositions): CompiledPreviewData {
            if ($fail) {
                throw new \App\Exceptions\PepDirectory\PreviewCompilationException();
            }
            $fields = $d['fields'];
            ksort($fields);
            $pairs = [];
            foreach ($fields as $key => $field) {
                $values = array_replace(['scope' => 'record', 'selector' => null, 'column' => null, 'constant' => null], $field);
                ksort($values);
                $pairs[] = [$key, $values];
            }
            $config = ['columns' => $d['columns'] ?? null, 'directory' => $d['directory'], 'fields' => $pairs,
                'record' => $d['record'], 'section' => $d['section'] ?? null, 'source' => $d['source'], 'url' => $d['url']];
            $encode = static fn (array $v): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $canonical = $encode(['config' => $config, 'contract' => 'configured-html-v1']);
            $revision = 'configured-html-v1:'.hash('sha256', $canonical);
            $positions = $resultPositions ?? [['unit' => 'Synthetic unit', 'role' => 'Synthetic role', 'person' => 'Synthetic holder',
                'condition' => 'a.i.', 'evidence' => '<script>synthetic</script>']];
            $wire = ['source' => $d['source'], 'revision' => $revision, 'observed_at' => $metadata['observed_at'],
                'validity' => 'incomplete', 'positions' => $positions, 'evidence' => $body];
            $result = $encode($wire);
            return CompiledPreviewData::fromOutput($encode(['format_version' => 1,
                'configuration' => ['declaration' => $d, 'canonical_base64' => base64_encode($canonical), 'revision' => $revision],
                'capture' => ['body_base64' => base64_encode($body), 'content_sha256' => hash('sha256', $body), 'metadata' => $metadata],
                'preview' => ['wire' => $wire, 'result_base64' => base64_encode($result), 'result_sha256' => hash('sha256', $result), 'validity' => 'incomplete']]), $d, $body, $metadata);
        });
        return $compiler;
    }

    private function source(User $actor, bool $capture = true): array
    {
        $fuente = $this->db->table('fuentes')->insertGetId(['label' => 'Synthetic configuration', 'url' => 'https://example.invalid/directory', 'tipo' => 'directorio']);
        $service = new DirectoryPreviewService($this->compiler());
        $source = $service->register($fuente, $actor);
        if (! $capture) {
            return [$source, null];
        }
        $request = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PepDirectory/preview-compiler/request.json'), true, 32, JSON_THROW_ON_ERROR);
        $stored = $service->recordCapture($source->id, ServerCaptureData::fromArray([
            'raw_body' => base64_decode($request['body_base64'], true), 'status' => 200,
            'requested_url' => 'https://example.invalid/directory', 'final_url' => 'https://example.invalid/directory',
            'captured_at' => '2026-10-18T12:00:00Z', 'encoding' => 'utf-8', 'provenance' => ['adapter' => 'SYNTHETIC UI fixture'],
        ]), $actor, $this->decision(1));
        return [$source->fresh(), $stored];
    }

    public function test_disabled_initial_and_hydrated_access_never_query_pep_with_absent_configuration_schema(): void
    {
        self::assertFalse(config('pep_directory.ui_enabled'));
        self::assertFalse($this->db->getSchemaBuilder()->hasTable('pep_directory_captures'));
        $actor = $this->syntheticUser();
        $queries = 0;
        $armed = true;
        $this->db->beforeExecuting(static function (string $sql) use (&$queries, &$armed): void {
            if ($armed && str_contains($sql, 'pep_directory_')) {
                $queries++;
                throw new \RuntimeException('Disabled configuration queried PEP.');
            }
        });
        try {
            Livewire::actingAs($actor)->test(ConfiguracionDirectorio::class)->assertNotFound();
            $this->get('/pep/directorio/configuracion')->assertNotFound();
            // A server-signed snapshot exercises the public hydration endpoint without schema overlay.
            $snapshot = Livewire::snapshot(Livewire::new(ConfiguracionDirectorio::class));
            $this->postJson('/livewire/update', ['components' => [[
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]]])->assertNotFound();
            self::assertSame(0, $queries);
        } finally {
            $armed = false;
        }
    }

    public function test_stored_capture_mapping_preview_confirmation_and_authorization_lifecycle(): void
    {
        $this->enable();
        (new \App\Providers\AppServiceProvider($this->app))->register();
        self::assertNull(config('pep_directory.preview_interpreter'));
        self::assertInstanceOf(\App\Services\PepDirectory\ProcessPreviewCompiler::class, app(PreviewCompiler::class));
        Livewire::test(ConfiguracionDirectorio::class)->assertUnauthorized();
        $actor = $this->syntheticUser();
        $actor->givePermissionTo(Permission::findOrCreate('gestionar fuentes', 'web'));
        [$source, $capture] = $this->source($actor);
        [$other, $foreignCapture] = $this->source($actor);
        [$empty] = $this->source($actor, false);
        $history = [];
        foreach (['observations', 'events', 'reviews'] as $table) {
            $history[$table] = serialize($this->db->table('pep_directory_'.$table)->orderBy('id')->get()->all());
        }
        // Rendering must not resolve the compiler, even when a binding exists.
        $this->app->bind(PreviewCompiler::class, static function (): never { throw new \LogicException('Compiler resolved during render'); });
        $page = Livewire::actingAs($actor)->test(ConfiguracionDirectorio::class)->assertOk()->assertSee($source->identity);
        $this->get('/pep/directorio/configuracion')->assertOk()->assertSee('Configuración de directorios');
        $page->call('selectSource', $empty->id)->assertSee('Sin captura almacenada; generación no disponible.');
        $this->app->forgetInstance(PreviewCompiler::class);
        $this->app->offsetUnset(PreviewCompiler::class);
        $page->call('selectSource', $source->id)->assertSee('Generación no disponible')->assertSet('captureId', $capture->id);
        $this->app['config']->set('pep_directory.preview_interpreter', '/synthetic/never-executed');
        $this->app->instance(PreviewCompiler::class, $this->compiler());
        try {
            $page->call('selectCapture', $foreignCapture->id);
            self::fail('A foreign capture must not be selectable.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertSame($capture->id, $page->get('captureId'));
        }
        $page = Livewire::actingAs($actor)->test(ConfiguracionDirectorio::class)->call('selectSource', $source->id)
            ->set('directory', 'table')->set('record', 'tr')->set('columns', '2')
            ->set('fields.unit.mode', 'constant')->set('fields.unit.value', 'Synthetic unit')
            ->set('fields.role.mode', 'column')->set('fields.role.value', '0')
            ->set('fields.person.mode', 'column')->set('fields.person.value', '1');
        foreach (['   ', str_repeat('á', 10001)] as $reason) {
            $page->set('reason', $reason)->call('publish')->assertHasErrors('reason');
        }
        $page->set('columns', '33')->set('reason', 'Mapeo inválido')->call('publish')->assertHasErrors('columns');
        $page->set('columns', '2')->set('fields.person.mode', 'constant')->call('publish')->assertHasErrors('mapping');
        self::assertSame(2, $source->fresh()->eligibility_version);
        $page->set('fields.person.mode', 'column')->set('reason', 'Mapeo revisado')->call('publish')->assertHasNoErrors()
            ->assertSet('displayedVersion', 3)->assertSee('Synthetic holder')->assertSee('incomplete')
            ->assertSee('&lt;script&gt;synthetic&lt;/script&gt;', false)->assertDontSee('<script>synthetic</script>', false)
            ->assertSee('Sin autoridad operativa');
        $preview = $source->fresh()->current_preview_id;
        // Stored confirmation does not need a compiler and must remain an explicit action.
        $this->app->offsetUnset(PreviewCompiler::class);
        $page->set('reason', 'Confirmación humana')->call('approve')->assertHasNoErrors()
            ->assertSee('Confirmación registrada. Detección no activada.')->assertSet('displayedVersion', 4);
        self::assertNotNull($source->fresh()->approved_decision_id);
        $page->set('record', 'tbody tr')->assertSee('Cambios sin previsualizar');
        $page->set('reason', 'No confirmar ediciones pendientes')->call('approve')->assertHasErrors('mapping');
        $this->app->instance(PreviewCompiler::class, $this->compiler(true));
        $before = $source->fresh()->getRawOriginal();
        $page->set('reason', 'Corrección')->call('publish')->assertHasErrors('preview');
        self::assertSame($before, $source->fresh()->getRawOriginal());
        $this->app->instance(PreviewCompiler::class, $this->compiler());
        $page->call('publish')->assertHasNoErrors()->assertSet('captureId', $capture->id)->assertSet('displayedVersion', 5);
        self::assertNull($source->fresh()->approved_decision_id);
        self::assertSame($capture->id, $source->fresh()->currentPreview->capture_id);
        self::assertNotSame($preview, $source->fresh()->current_preview_id);
        $page->call('reloadSource')->assertSet('record', 'tbody tr');
        $this->app->offsetUnset(PreviewCompiler::class);
        // A concurrent real service decision fences the displayed version until explicit reload.
        (new ConfigurationService())->approve($source->id, $source->fresh()->current_preview_id, $actor, $this->decision(5));
        $page->set('reason', 'Revocar')->call('revoke')->assertSet('conflict', true)->assertSet('displayedVersion', 5)->assertSee('Recargue');
        $page->call('revoke')->assertSet('displayedVersion', 5);
        self::assertSame(6, $source->fresh()->eligibility_version);
        $page->call('reloadSource')->assertSet('reason', '')->assertSet('conflict', false)->assertSet('displayedVersion', 6)
            ->set('reason', 'Nueva decisión')->call('revoke')->assertHasNoErrors()->assertSet('displayedVersion', 7);
        self::assertNull($source->fresh()->approved_decision_id);
        $receipt = $source->fresh()->configurationDecisions->last();
        self::assertSame('user:'.$actor->id, $receipt->actor_identity);
        self::assertSame('revoke', $receipt->action);
        // All identity/version anchors are locked against public Livewire property updates.
        foreach (['sourceId', 'captureId', 'previewId', 'displayedVersion'] as $property) {
            $locked = Livewire::actingAs($actor)->test(ConfiguracionDirectorio::class)->call('selectSource', $source->id);
            try {
                $locked->set($property, 999999);
                self::fail('Unlocked '.$property);
            } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
                self::assertSame(7, $source->fresh()->eligibility_version);
            }
        }
        // Exercise selector/section context, optional condition and all column boundaries.
        $this->app->instance(PreviewCompiler::class, $this->compiler());
        $selectors = Livewire::actingAs($actor)->test(ConfiguracionDirectorio::class)->call('selectSource', $other->id)
            ->set('directory', 'main')->set('record', '.card')->set('section', 'section')->set('columns', '32')
            ->set('fields.unit.mode', 'selector')->set('fields.unit.scope', 'section')->set('fields.unit.value', 'h2')
            ->set('fields.role.value', '.role')->set('fields.person.value', '.person')
            ->set('fields.condition.mode', 'column')->set('fields.condition.value', '31')
            ->set('reason', 'Selectores revisados')->call('publish')->assertHasNoErrors()->assertSet('displayedVersion', 3);
        self::assertSame(['selector' => 'h2', 'scope' => 'section'], $other->fresh()->draftConfiguration->declaration['fields']['unit']);
        // The real approval service, not the UI, rejects a null person or an empty result.
        foreach ([[], [['unit' => 'Synthetic unit', 'role' => 'Synthetic role', 'person' => null, 'condition' => null, 'evidence' => 'Synthetic evidence']]] as $positions) {
            $this->app->instance(PreviewCompiler::class, $this->compiler(resultPositions: $positions));
            $selectors->set('reason', 'Rechazo sintético')->call('publish')->assertHasNoErrors();
            $unchanged = $other->fresh()->getRawOriginal();
            $selectors->set('reason', 'No confirmar extracción rechazada')->call('approve')->assertHasErrors('preview');
            self::assertSame($unchanged, $other->fresh()->getRawOriginal());
        }
        $this->app->instance(PreviewCompiler::class, $this->compiler(resultPositions: [['unit' => 'Synthetic unit', 'role' => 'Synthetic role', 'person' => '   ', 'condition' => null, 'evidence' => 'Synthetic evidence']]));
        $selectors->set('reason', 'Persona vacía')->call('publish')->assertHasErrors('preview');
        // Persisted manage revocation is checked even though the browser actor remains loaded.
        $actor->revokePermissionTo('gestionar fuentes');
        foreach (['publish', 'approve', 'revoke'] as $action) {
            Livewire::actingAs($actor)->test(ConfiguracionDirectorio::class)->call('selectSource', $source->id)
                ->set('reason', 'No autorizado')->call($action)->assertForbidden();
        }
        $actor->revokePermissionTo('ver cambios pep');
        $page->call('$refresh')->assertForbidden();
        $actor->givePermissionTo('ver cambios pep');
        $page = Livewire::actingAs($actor)->test(ConfiguracionDirectorio::class)->assertOk();
        $this->db->table('users')->where('id', $actor->id)->update(['activo' => false]);
        $page->call('$refresh')->assertForbidden();
        self::assertSame(7, $source->fresh()->eligibility_version);
        foreach ($history as $table => $rows) {
            self::assertSame($rows, serialize($this->db->table('pep_directory_'.$table)->orderBy('id')->get()->all()));
        }
        self::assertSame(2, $this->db->table('pep_directory_captures')->count());
    }
}
