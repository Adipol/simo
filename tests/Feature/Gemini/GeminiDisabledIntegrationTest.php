<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Jobs\AnalizarCambioConPro;
use App\Jobs\AnalizarScrapingConFlash;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeminiDisabledIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createResultadoScraping(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article-' . uniqid(),
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Test',
            'contexto' => 'Context',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function createCambio(array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => Fuente::create([
                'url' => 'https://example.com/gobierno',
                'nombre' => 'Gobierno Test',
                'pais' => 'BO',
                'organismo' => 'Ministerio Test',
                'activo' => true,
            ])->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'diff_texto' => '- Old\n+ New',
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_disabled_config_observer_does_not_dispatch(): void
    {
        config(['services.gemini.enabled' => false]);

        Queue::fake();

        // Re-register observers for this test
        ResultadoScraping::observe(\App\Observers\ResultadoScrapingObserver::class);
        Cambio::observe(\App\Observers\CambioObserver::class);

        $this->createResultadoScraping();
        $this->createCambio();

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }

    public function test_disabled_config_command_does_not_dispatch(): void
    {
        config(['services.gemini.enabled' => false]);

        $this->createResultadoScraping();
        $this->createCambio();

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->expectsOutputToContain('deshabilitado')
            ->assertExitCode(0);

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }

    public function test_disabled_config_job_handle_is_noop(): void
    {
        config(['services.gemini.enabled' => false]);

        $record = $this->createResultadoScraping();
        $cambio = $this->createCambio();

        // Run jobs directly — they should return early
        (new AnalizarScrapingConFlash)->handle();
        (new AnalizarCambioConPro)->handle();

        // Records remain unanalyzed
        $record->refresh();
        $cambio->refresh();

        $this->assertFalse($record->gemini_analyzed);
        $this->assertFalse($cambio->gemini_analyzed);
    }

    public function test_disabled_records_gemini_analyzed_stays_false(): void
    {
        config(['services.gemini.enabled' => false]);

        Queue::fake();

        // Register observers so creates would normally dispatch
        ResultadoScraping::observe(\App\Observers\ResultadoScrapingObserver::class);
        Cambio::observe(\App\Observers\CambioObserver::class);

        $r1 = $this->createResultadoScraping();
        $r2 = $this->createResultadoScraping();
        $c1 = $this->createCambio();

        // No dispatches happened
        Queue::assertNothingPushed();

        // All still false
        $this->assertFalse($r1->fresh()->gemini_analyzed);
        $this->assertFalse($r2->fresh()->gemini_analyzed);
        $this->assertFalse($c1->fresh()->gemini_analyzed);
    }
}
