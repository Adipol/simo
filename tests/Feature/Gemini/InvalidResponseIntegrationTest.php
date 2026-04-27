<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvalidResponseIntegrationTest extends TestCase
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
            'titulo' => 'Test Article',
            'contexto' => 'Some context about a minister',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_invalid_json_response_marks_analyzed_and_logs_error(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $record = $this->createRecord(['contexto' => 'El ministro de economía firmó el decreto']);

        // Fake plain text response (simulates Gemini returning non-JSON)
        $plainText = file_get_contents(base_path('tests/Fixtures/Gemini/invalid_text_response.txt'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($plainText, 200),
        ]);

        // The job should NOT crash — it catches GeminiInvalidResponseException
        $job = new \App\Jobs\AnalizarScrapingConFlash;
        $job->handle();

        // Record is marked as analyzed even on failure
        $record->refresh();
        $this->assertTrue($record->gemini_analyzed);

        // gemini_is_pep must be false (deterministic — never left as null after failure)
        $this->assertFalse($record->gemini_is_pep);
        $this->assertNull($record->gemini_nombre);
        $this->assertNull($record->gemini_cargo);
        $this->assertNull($record->gemini_categoria);
        $this->assertNull($record->gemini_confianza);
        $this->assertNull($record->gemini_motivo);
    }

    public function test_invalid_response_does_not_crash_job(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $record = $this->createRecord();

        $plainText = file_get_contents(base_path('tests/Fixtures/Gemini\invalid_text_response.txt'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($plainText, 200),
        ]);

        // This should complete without throwing
        $job = new \App\Jobs\AnalizarScrapingConFlash;

        try {
            $job->handle();
            $didNotThrow = true;
        } catch (\Throwable $e) {
            $didNotThrow = false;
        }

        $this->assertTrue($didNotThrow, 'Job should not crash on invalid JSON response');
    }

    public function test_invalid_response_other_records_still_processed(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $r1 = $this->createRecord(['contexto' => 'El presidente designó Record 1']);
        $r2 = $this->createRecord(['contexto' => 'Record 2']);

        // First call returns valid new-format response, second returns invalid
        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;

                if ($callCount === 1) {
                    return Http::response(json_encode([
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
                                            'motivo' => 'Cargo ejecutivo',
                                        ]],
                                        'motivo_general' => 'Artículo ministerial',
                                    ]),
                                ]],
                            ],
                        ]],
                    ]), 200);
                }

                return Http::response('Lo siento, no puedo ayudar con esa solicitud.', 200);
            },
        ]);

        $job = new \App\Jobs\AnalizarScrapingConFlash;
        $job->handle();

        // r1 processed successfully — valid response with PEP persona at threshold
        $r1->refresh();
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertTrue($r1->gemini_is_pep);

        // r2 marked as analyzed with gemini_is_pep=false (deterministic failure)
        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertFalse($r2->gemini_is_pep, 'gemini_is_pep must be false (not null) after invalid response failure');
    }
}
