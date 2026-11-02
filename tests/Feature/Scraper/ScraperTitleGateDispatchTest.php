<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Jobs\AnalizarCambioConPro;
use App\Jobs\AnalizarScrapingConFlash;
use App\Models\Cambio;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScraperTitleGateDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.enabled' => true, 'services.deepseek_title_gate.enabled' => false]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_command_excludes_held_unrelated_but_preserves_all_legacy_pending_categories(): void
    {
        Http::fake();
        $primary = $this->record(['gemini_analyzed' => true]);
        $this->record(['deepseek_title_status' => 'unrelated']);
        foreach ([null, 'relevant', 'doubtful', 'technical_error', 'invalid_response'] as $status) {
            $this->record(['deepseek_title_status' => $status, 'descartado' => true,
                'archivado_at' => now(), 'secundario_de' => $primary->id]);
        }
        $this->record(['deepseek_title_status' => 'unrelated', 'deepseek_bypass_at' => now()]);
        // The grouped OR must not admit already-analyzed bypasses.
        $this->record(['gemini_analyzed' => true, 'deepseek_bypass_at' => now()]);
        Cambio::withoutEvents(fn () => Cambio::factory()->create(['gemini_analyzed' => false]));

        $this->artisan('simo:analizar-gemini')->expectsOutput('Flash: 6 pendientes')
            ->expectsOutput('Pro: 1 pendientes')->assertExitCode(0);

        Queue::assertPushed(AnalizarScrapingConFlash::class, 1);
        Queue::assertPushed(AnalizarCambioConPro::class, 1);
        Queue::assertPushed(AnalizarScrapingConFlash::class, fn ($job): bool => $job->queue === 'gemini');
        Http::assertNothingSent();
    }

    public function test_held_only_does_not_dispatch_from_command_or_job(): void
    {
        Http::fake();
        $this->record(['deepseek_title_status' => 'unrelated']);

        $this->artisan('simo:analizar-gemini', ['--flash-only' => true])
            ->expectsOutput('Flash: 0 pendientes')->assertExitCode(0);
        (new AnalizarScrapingConFlash)->handle();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(1, ResultadoScraping::where('gemini_analyzed', false)->count());
    }

    public function test_active_gate_batches_one_and_self_chains_only_for_eligible_remaining_rows(): void
    {
        config(['services.deepseek_title_gate' => [
            'enabled' => true, 'api_key' => 'fake', 'model' => 'deepseek-flash',
            'activation_after_id' => 0, 'timeout' => 20, 'batch_size' => 1,
        ]]);
        $this->record(['deepseek_title_status' => 'unrelated']);
        $this->record(['fecha_encontrado' => now()->subMinute()]);
        $this->record(['fecha_encontrado' => now()]);
        Http::fake(['api.deepseek.com/*' => function ($request) {
            $input = json_decode($request['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);
            return Http::response(['model' => 'deepseek-flash', 'choices' => [[
                'finish_reason' => 'stop', 'message' => ['content' => json_encode(['resultados' => [[
                    'id' => $input['id'], 'decision' => 'unrelated', 'evidencia' => 'partido', 'motivo' => 'Deportes',
                ]]], JSON_THROW_ON_ERROR)],
            ]]]);
        }]);

        (new AnalizarScrapingConFlash)->handle();
        Queue::assertPushed(AnalizarScrapingConFlash::class, 1);
        $this->assertSame(2, ResultadoScraping::where('deepseek_title_status', 'unrelated')->count());
        Queue::fake();
        (new AnalizarScrapingConFlash)->handle();
        Queue::assertNothingPushed();
        Http::assertSentCount(2);
        $this->assertSame(3, ResultadoScraping::where('gemini_analyzed', false)->count());
    }

    private function record(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::withoutEvents(fn (): ResultadoScraping => ResultadoScraping::factory()->create(array_merge([
            'titulo' => 'Un partido amistoso', 'contexto' => 'Un partido amistoso', 'gemini_analyzed' => false,
        ], $overrides)));
    }
}
