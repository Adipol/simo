<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

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
            'url' => 'https://example.com/article',
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Ministro de Economía',
            'contexto' => 'El ministro de Economía Juan Pérez firmó un decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_full_flash_pipeline_observer_dispatches_job_updates_db(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        // 1. Create record — observer is flushed, no dispatch
        $record = $this->createRecord();
        $this->assertFalse($record->gemini_analyzed);

        // 2. Fake the HTTP response using fixture
        $fixture = file_get_contents(base_path('tests/Fixtures/Gemini/flash_success.json'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(json_decode($fixture, true), 200),
        ]);

        // 3. Fake the queue to capture dispatch, then call handle() directly
        Queue::fake();

        // Manually dispatch and then process
        \App\Jobs\AnalizarScrapingConFlash::dispatch();
        Queue::assertPushed(\App\Jobs\AnalizarScrapingConFlash::class);

        // 4. Run the job directly (not via queue worker)
        $job = new \App\Jobs\AnalizarScrapingConFlash;
        $job->handle();

        // 5. Assert all 7 gemini_* fields are populated
        $record->refresh();

        $this->assertTrue($record->gemini_analyzed);
        $this->assertTrue($record->gemini_is_pep);
        $this->assertSame('Juan Pérez', $record->gemini_nombre);
        $this->assertSame('Ministro de Economía', $record->gemini_cargo);
        $this->assertSame('PEP', $record->gemini_categoria);
        $this->assertSame(95, $record->gemini_confianza);
        $this->assertSame('Cargo ejecutivo de alto nivel en cartera ministerial', $record->gemini_motivo);
    }

    public function test_flash_pipeline_marks_multiple_records(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $r1 = $this->createRecord(['contexto' => 'Record 1']);
        $r2 = $this->createRecord(['contexto' => 'Record 2']);

        $fixture = file_get_contents(base_path('tests/Fixtures/Gemini/flash_success.json'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(json_decode($fixture, true), 200),
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
            'contexto' => 'Already done',
            'gemini_analyzed' => true,
            'gemini_is_pep' => false,
        ]);

        $pending = $this->createRecord(['contexto' => 'Pending record']);

        $fixture = file_get_contents(base_path('tests/Fixtures/Gemini/flash_success.json'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(json_decode($fixture, true), 200),
        ]);

        $job = new \App\Jobs\AnalizarScrapingConFlash;
        $job->handle();

        // Already analyzed record is untouched
        $analyzed->refresh();
        $this->assertFalse($analyzed->gemini_is_pep);

        // Pending record is now analyzed
        $pending->refresh();
        $this->assertTrue($pending->gemini_analyzed);
        $this->assertTrue($pending->gemini_is_pep);
    }
}
