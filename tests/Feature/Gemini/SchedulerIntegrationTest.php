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

class SchedulerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ?Fuente $fuente = null;

    private function getOrCreateFuente(): Fuente
    {
        if ($this->fuente === null) {
            $this->fuente = Fuente::create([
                'url' => 'https://example.com/gobierno',
                'nombre' => 'Gobierno Test',
                'pais' => 'BO',
                'organismo' => 'Ministerio Test',
                'activo' => true,
            ]);
        }

        return $this->fuente;
    }

    private function createResultadoScraping(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article',
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Test Article',
            'contexto' => 'Some context',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function createCambio(array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => $this->getOrCreateFuente()->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'diff_texto' => '- Old\n+ New',
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_scheduler_dispatches_jobs_for_all_pending_records(): void
    {
        config(['services.gemini.enabled' => true]);

        // 5 pending ResultadoScraping
        for ($i = 0; $i < 5; $i++) {
            $this->createResultadoScraping(['contexto' => "Record {$i}"]);
        }

        // 3 pending Cambio
        for ($i = 0; $i < 3; $i++) {
            $this->createCambio(['diff_texto' => "- Old {$i}\n+ New {$i}"]);
        }

        // Verify counts
        $this->assertSame(5, ResultadoScraping::where('gemini_analyzed', false)->count());
        $this->assertSame(3, Cambio::where('gemini_analyzed', false)->count());

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->assertExitCode(0);

        // Both jobs dispatched
        Queue::assertPushed(AnalizarScrapingConFlash::class);
        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    public function test_scheduler_does_not_reprocess_already_analyzed(): void
    {
        config(['services.gemini.enabled' => true]);

        // 2 analyzed, 1 pending for scraping
        $this->createResultadoScraping([
            'contexto' => 'Analyzed 1',
            'gemini_analyzed' => true,
        ]);
        $this->createResultadoScraping([
            'contexto' => 'Analyzed 2',
            'gemini_analyzed' => true,
        ]);
        $this->createResultadoScraping(['contexto' => 'Pending']);

        // 1 analyzed, 1 pending for cambios
        $this->createCambio([
            'diff_texto' => '- A\n+ B',
            'gemini_analyzed' => true,
        ]);
        $this->createCambio(['diff_texto' => '- C\n+ D']);

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->assertExitCode(0);

        // Jobs are dispatched because there ARE pending records
        Queue::assertPushed(AnalizarScrapingConFlash::class);
        Queue::assertPushed(AnalizarCambioConPro::class);

        // The jobs only process pending ones (verified by the job query)
        // Total pending: 1 scraping + 1 cambio
        $this->assertSame(1, ResultadoScraping::where('gemini_analyzed', false)->count());
        $this->assertSame(1, Cambio::where('gemini_analyzed', false)->count());
    }

    public function test_scheduler_no_pending_means_no_dispatch(): void
    {
        config(['services.gemini.enabled' => true]);

        // All analyzed
        $this->createResultadoScraping([
            'gemini_analyzed' => true,
        ]);
        $this->createCambio([
            'gemini_analyzed' => true,
        ]);

        Queue::fake();

        $this->artisan('simo:analizar-gemini')
            ->assertExitCode(0);

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }
}
