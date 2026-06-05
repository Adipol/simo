<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Jobs\AnalizarCambioConPro;
use App\Jobs\AnalizarScrapingConFlash;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Validates job $timeout property and batch size limits.
 *
 * Timeout pyramid invariant (code side):
 *   max HTTP (60s) < job $timeout (300s) < retry_after (360s, infra)
 */
class GeminiJobPropertiesTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Task 4.1 — Flash: $timeout=300, batch=10
    // =========================================================================

    public function test_flash_job_has_timeout_300(): void
    {
        $job = new AnalizarScrapingConFlash;
        $this->assertSame(300, $job->timeout);
    }

    public function test_flash_job_batch_processes_max_10_records(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        for ($i = 0; $i < 15; $i++) {
            $this->createFlashRecord(['contexto' => "Record {$i} — El ministro firmó decreto."]);
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeFlashResponse(), 200
            ),
        ]);

        Queue::fake();

        (new AnalizarScrapingConFlash)->handle();

        // Batch cap: exactly 10 processed, 5 remain
        $this->assertSame(10, ResultadoScraping::where('gemini_analyzed', true)->count());
        $this->assertSame(5, ResultadoScraping::where('gemini_analyzed', false)->count());

        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }

    // =========================================================================
    // Task 4.3 — Pro: $timeout=300, batch=3
    // =========================================================================

    public function test_pro_job_has_timeout_300(): void
    {
        $job = new AnalizarCambioConPro;
        $this->assertSame(300, $job->timeout);
    }

    public function test_pro_job_batch_processes_max_3_records(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $fuente = $this->createFuente();

        for ($i = 0; $i < 10; $i++) {
            $this->createCambio($fuente, ['diff_texto' => "-Persona {$i}\n+Nueva {$i}"]);
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeProResponse(), 200
            ),
        ]);

        Queue::fake();

        (new AnalizarCambioConPro)->handle();

        // Batch cap: exactly 3 processed, 7 remain
        $this->assertSame(3, Cambio::where('gemini_analyzed', true)->count());
        $this->assertSame(7, Cambio::where('gemini_analyzed', false)->count());

        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    // =========================================================================
    // Task 6.2 — Timeout invariant comment
    // =========================================================================

    public function test_timeout_invariant_holds_in_config(): void
    {
        $maxHttp = max(
            (int) config('services.gemini.timeout', 45),
            (int) config('services.gemini.multimodal_timeout', 60),
        );
        $jobTimeout = (new AnalizarCambioConPro)->timeout;

        // max HTTP (60) < job timeout (300) < retry_after (360, infra)
        $this->assertLessThan(
            $jobTimeout,
            $maxHttp,
            "Invariant violation: max HTTP ({$maxHttp}s) must be < job \$timeout ({$jobTimeout}s)"
        );
        $this->assertSame(300, $jobTimeout, 'Pro job $timeout must be 300s');
        $this->assertSame(300, (new AnalizarScrapingConFlash)->timeout, 'Flash job $timeout must be 300s');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createFuente(array $overrides = []): Fuente
    {
        return Fuente::create(array_merge([
            'url' => 'https://gobierno.bo/test',
            'nombre' => 'Test Ministry',
            'organismo' => 'Test Organism',
            'pais' => 'BO',
        ], $overrides));
    }

    private function createCambio(Fuente $fuente, array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'diff_texto' => "-Old\n+New",
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function createFlashRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article-' . uniqid(),
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Test Article',
            'contexto' => 'El ministro firmó un decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function fakeFlashResponse(): string
    {
        return json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'personas' => [],
                    'motivo_general' => 'No PEP detected.',
                ])]]],
            ]],
        ]);
    }

    private function fakeProResponse(): string
    {
        return json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'persona_removida' => null,
                    'persona_nueva'    => null,
                    'cargo'            => null,
                    'es_mae'           => false,
                    'riesgo'           => 'bajo',
                    'analisis'         => 'No change.',
                ])]]],
            ]],
        ]);
    }
}
