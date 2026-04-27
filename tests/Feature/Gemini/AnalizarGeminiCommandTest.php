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

class AnalizarGeminiCommandTest extends TestCase
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
            'titulo' => 'Ministro de Economía',
            'contexto' => 'El ministro de Economía Juan Pérez firmó un decreto.',
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
                'organismo' => 'Ministerio de Test',
                'activo' => true,
            ])->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'diff_texto' => '- Ministro Juan Pérez\n+ Ministra María López',
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_dispatches_both_jobs_when_both_have_pending(): void
    {
        config(['services.gemini.enabled' => true]);

        $this->createResultadoScraping();
        $this->createCambio();

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->assertExitCode(0);

        Queue::assertPushed(AnalizarScrapingConFlash::class);
        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    public function test_flash_only_dispatches_only_flash_job(): void
    {
        config(['services.gemini.enabled' => true]);

        $this->createResultadoScraping();
        $this->createCambio();

        Queue::fake();

        $this->artisan('simo:analizar-gemini --flash-only')
            ->assertExitCode(0);

        Queue::assertPushed(AnalizarScrapingConFlash::class);
        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }

    public function test_pro_only_dispatches_only_pro_job(): void
    {
        config(['services.gemini.enabled' => true]);

        $this->createResultadoScraping();
        $this->createCambio();

        Queue::fake();

        $this->artisan('simo:analizar-gemini --pro-only')
            ->assertExitCode(0);

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    public function test_no_pending_records_does_not_dispatch(): void
    {
        config(['services.gemini.enabled' => true]);

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->assertExitCode(0);

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }

    public function test_disabled_does_not_dispatch(): void
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

    public function test_output_shows_pending_counts(): void
    {
        config(['services.gemini.enabled' => true]);

        $this->createResultadoScraping();
        $this->createResultadoScraping();
        $this->createResultadoScraping();
        $this->createCambio();

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->expectsOutputToContain('Flash: 3 pendientes')
            ->expectsOutputToContain('Pro: 1 pendientes')
            ->assertExitCode(0);
    }

    public function test_no_pending_shows_zero_counts(): void
    {
        config(['services.gemini.enabled' => true]);

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->expectsOutputToContain('Flash: 0 pendientes')
            ->expectsOutputToContain('Pro: 0 pendientes')
            ->assertExitCode(0);
    }

    public function test_jobs_are_dispatched_on_gemini_queue(): void
    {
        config(['services.gemini.enabled' => true]);

        $this->createResultadoScraping();
        $this->createCambio();

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->assertExitCode(0);

        Queue::assertPushed(AnalizarScrapingConFlash::class, function ($job) {
            return $job->queue === 'gemini';
        });

        Queue::assertPushed(AnalizarCambioConPro::class, function ($job) {
            return $job->queue === 'gemini';
        });
    }

    public function test_skipped_job_does_not_output_its_count(): void
    {
        config(['services.gemini.enabled' => true]);

        $this->createResultadoScraping();
        $this->createCambio();

        Queue::fake();

        $this->artisan('simo:analizar-gemini --flash-only')
            ->expectsOutputToContain('Flash: 1 pendientes')
            ->assertExitCode(0);
    }
}
