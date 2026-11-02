<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Jobs\AnalizarScrapingConFlash;
use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoScraping;
use App\Models\User;
use App\Services\ResultadoScrapingQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ResultadosTitleGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['services.gemini.enabled' => true, 'services.gemini.api_key' => 'fake',
            'services.deepseek_title_gate.enabled' => false, 'services.dedupe.enabled' => false]);
        Permission::findOrCreate('gestionar resultados', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('gestionar resultados');
        $this->actingAs($user);
    }

    private function article(string $title, array $attributes = []): ResultadoScraping
    {
        return ResultadoScraping::withoutEvents(fn (): ResultadoScraping => ResultadoScraping::factory()->create(array_merge([
            'titulo' => $title, 'keyword' => $title, 'gemini_analyzed' => false, 'gemini_analyzed_at' => null,
            'deepseek_title_status' => 'unrelated', 'deepseek_bypass_at' => null,
            'secundario_de' => null, 'leido' => false, 'relevante' => null,
            'descartado' => false, 'archivado_at' => null, 'pais' => 'BO', 'categoria' => 'politica',
        ], $attributes)))->refresh();
    }

    public function test_default_query_legacy_signature_and_optional_filter_preserve_explicit_filters(): void
    {
        $primary = $this->article('processed primary', ['gemini_analyzed' => true]);
        $held = $this->article('held secondary', ['secundario_de' => $primary->id]);
        $this->article('other pending', ['deepseek_title_status' => 'relevant']);
        $this->article('already bypassed', ['deepseek_bypass_at' => now()]);
        $service = new ResultadoScrapingQueryService;
        $this->assertSame([$primary->id], $service->buildQuery()->pluck('id')->all());
        $this->assertSame([$primary->id], $service->buildQuery('', '', '', '', '', '0', '0', '', '', 'fecha_encontrado', 'desc')->pluck('id')->all());
        $this->assertSame([$held->id], $service->buildQuery(filtroDeepSeek: 'held')->pluck('id')->all());
        foreach (['filtroPais' => 'AR', 'filtroCategoria' => 'deportes', 'filtroLeido' => '1',
            'filtroRelevante' => '1', 'filtroDescartado' => '1', 'filtroArchivado' => '1',
            'filtroIds' => (string) $primary->id, 'busqueda' => 'absent', 'filtroGemini' => 'pep'] as $filter => $value) {
            $this->assertSame([], $service->buildQuery(...['filtroDeepSeek' => 'held', $filter => $value])->pluck('id')->all(), $filter);
        }
        Livewire::test(Resultados::class)->assertSee('processed primary')->assertDontSeeHtml('wire:click="enviarAGemini('.$held->id.')"')
            ->set('filtroDeepSeek', 'held')->assertSee('held secondary')->assertDontSee('other pending')->assertDontSee('already bypassed');
    }

    public function test_url_filter_validation_pagination_clear_and_export_share_query(): void
    {
        $held = $this->article('Only held export');
        $this->article('Never exported default', ['gemini_analyzed' => true]);
        $property = new \ReflectionProperty(Resultados::class, 'filtroDeepSeek');
        $this->assertNotEmpty($property->getAttributes(Url::class));
        Livewire::withQueryParams(['filtroDeepSeek' => 'held'])->test(Resultados::class)
            ->assertSet('filtroDeepSeek', 'held')->assertSee($held->titulo);
        $component = Livewire::test(Resultados::class)->call('gotoPage', 2)
            ->set('filtroDeepSeek', 'held')->assertSet('paginators.page', 1)
            ->assertSee('No enviadas por DeepSeek')->call('exportarCsv')->assertFileDownloaded();
        $csv = base64_decode($component->effects['download']['content'], true);
        $this->assertStringContainsString('Only held export', $csv);
        $this->assertStringNotContainsString('Never exported default', $csv);
        $component->call('limpiarFiltros')->assertSet('filtroDeepSeek', '')->assertSet('paginators.page', 1)
            ->set('filtroDeepSeek', 'unrelated OR 1=1')->assertHasErrors('filtroDeepSeek')->assertSet('filtroDeepSeek', '');
        Http::assertNothingSent();
    }

    public function test_authorized_action_schedules_once_and_updates_pending_count_without_changing_defaults(): void
    {
        $record = $this->article('Held action');
        $this->article('Pending action', ['deepseek_title_status' => 'relevant']);
        $before = $record->getAttributes();
        $component = Livewire::test(Resultados::class)->assertSet('pendingCount', 1)
            ->set('filtroDeepSeek', 'held')->assertSee('Enviar a Gemini')
            ->call('enviarAGemini', $record->id)->assertSee('Análisis programado en Gemini')
            ->assertSet('pendingCount', 2)->assertDontSeeHtml('wire:click="enviarAGemini('.$record->id.')"')
            ->call('enviarAGemini', $record->id)->assertSee('El artículo ya no admite este envío');
        unset($before['deepseek_bypass_at']);
        $after = $record->refresh()->getAttributes();
        unset($after['deepseek_bypass_at']);
        $this->assertSame($before, $after);
        Queue::assertPushed(AnalizarScrapingConFlash::class, 1);
        Http::assertNothingSent();
    }

    public function test_permission_is_required_even_for_direct_action_and_no_button_is_exposed(): void
    {
        $record = $this->article('Unauthorized held');
        $before = $record->getAttributes();
        $this->actingAs(User::factory()->create());
        Livewire::test(Resultados::class)->set('filtroDeepSeek', 'held')->assertDontSee('Enviar a Gemini')
            ->call('enviarAGemini', $record->id)->assertForbidden();
        $this->assertSame($before, $record->refresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    public function test_invalid_ids_reject_without_mutation_and_disabled_gemini_reports_truthfully(): void
    {
        $record = $this->article('Rejected action');
        $before = $record->getAttributes();
        foreach ([0, -1, 999999] as $id) {
            Livewire::test(Resultados::class)->call('enviarAGemini', $id)->assertHasErrors('id');
        }
        config(['services.gemini.enabled' => false]);
        Livewire::test(Resultados::class)->call('enviarAGemini', $record->id)
            ->assertSee('Gemini está deshabilitado o sin configurar');
        $this->assertSame($before, $record->refresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    public function test_reason_and_evidence_are_escaped_and_health_state_contains_no_secret(): void
    {
        config(['services.deepseek_title_gate' => ['enabled' => true, 'api_key' => 'secret-title-key',
            'model' => 'deepseek-flash', 'activation_after_id' => 0, 'timeout' => 20, 'batch_size' => 1]]);
        $this->article('Escaped held', ['deepseek_title_reason' => '<script>alert(1)</script>',
            'deepseek_title_evidence' => '<img src=x onerror=alert(2)>', 'deepseek_title_error_code' => 'private provider error']);
        $component = Livewire::test(Resultados::class)->assertSee('Configurada; aún sin llamadas verificadas')
            ->set('filtroDeepSeek', 'held')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSee('private provider error')->assertDontSee('secret-title-key');
        $this->assertStringNotContainsString('secret-title-key', $component->html());
        Http::assertNothingSent();
    }
}
