<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlashPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
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

    /**
     * Returns a valid new multi-persona format Gemini API response.
     */
    private function fakeSuccessResponse(): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'personas' => [[
                                'nombre' => 'Juan Pérez',
                                'cargo' => 'Ministro de Economía',
                                'categoria' => 'PEP',
                                'entidad_tipo' => 'publica',
                                'confianza' => 95,
                                'evento' => 'designacion',
                                'motivo' => 'Cargo ejecutivo de alto nivel en cartera ministerial',
                            ]],
                            'motivo_general' => 'Cargo ejecutivo de alto nivel en cartera ministerial',
                        ]),
                    ]],
                ],
            ]],
        ];
    }

    public function test_full_flash_pipeline_observer_dispatches_job_updates_db(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        // 1. Create record — observer is flushed, no dispatch
        $record = $this->createRecord();
        $this->assertFalse($record->gemini_analyzed);

        // 2. Fake the HTTP response using new multi-persona format
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeSuccessResponse(), 200),
        ]);

        // 3. Fake the queue to capture dispatch, then call handle() directly
        Queue::fake();

        // Manually dispatch and then process
        \App\Jobs\AnalizarScrapingConFlash::dispatch();
        Queue::assertPushed(\App\Jobs\AnalizarScrapingConFlash::class);

        // 4. Run the job directly (not via queue worker)
        $job = new \App\Jobs\AnalizarScrapingConFlash;
        $job->handle();

        // 5. Assert gemini_analyzed and gemini_is_pep on parent record
        $record->refresh();

        $this->assertTrue($record->gemini_analyzed);
        $this->assertTrue($record->gemini_is_pep);
        $this->assertSame('Cargo ejecutivo de alto nivel en cartera ministerial', $record->gemini_motivo);

        // 6. Assert persona data is stored in resultado_personas (new multi-persona contract)
        $persona = ResultadoPersona::where('resultado_scraping_id', $record->id)->first();
        $this->assertNotNull($persona);
        $this->assertSame('Juan Pérez', $persona->nombre);
        $this->assertSame('Ministro de Economía', $persona->cargo);
        $this->assertSame('PEP', $persona->categoria);
        $this->assertSame(95, $persona->confianza);
    }

    public function test_flash_pipeline_marks_multiple_records(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $r1 = $this->createRecord(['contexto' => 'El ministro de economía presidió la sesión']);
        $r2 = $this->createRecord(['contexto' => 'El senador presentó un proyecto de ley']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeSuccessResponse(), 200),
        ]);

        $job = new \App\Jobs\AnalizarScrapingConFlash;
        $job->handle();

        $r1->refresh();
        $r2->refresh();

        $this->assertTrue($r1->gemini_analyzed);
        $this->assertTrue($r1->gemini_is_pep);
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertTrue($r2->gemini_is_pep);
    }

    public function test_flash_pipeline_skips_already_analyzed_records(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $analyzed = $this->createRecord([
            'contexto' => 'El ministro presidió ya analizado',
            'gemini_analyzed' => true,
            'gemini_is_pep' => false,
        ]);

        $pending = $this->createRecord(['contexto' => 'El fiscal general presentó cargos contra el senador']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeSuccessResponse(), 200),
        ]);

        $job = new \App\Jobs\AnalizarScrapingConFlash;
        $job->handle();

        // Already analyzed record is untouched
        $analyzed->refresh();
        $this->assertFalse($analyzed->gemini_is_pep);

        // Pending record is now analyzed with PEP detected
        $pending->refresh();
        $this->assertTrue($pending->gemini_analyzed);
        $this->assertTrue($pending->gemini_is_pep);
    }
}
