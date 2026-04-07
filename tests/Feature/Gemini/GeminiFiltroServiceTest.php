<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Exceptions\Gemini\GeminiServerException;
use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiFiltroServiceTest extends TestCase
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

    private function fakeGeminiResponse(array $data): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($data),
                    ]],
                ],
            ]],
        ]);
    }

    private function makeService(): GeminiFiltroService
    {
        return new GeminiFiltroService(
            new GeminiService(apiKey: 'test-key-123'),
            new GeminiPromptBuilder,
        );
    }

    public function test_analizar_lote_updates_all_gemini_fields_on_happy_path(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $r1 = $this->createRecord(['contexto' => 'Ministro Juan Pérez firmó decreto']);
        $r2 = $this->createRecord(['contexto' => 'Líder del cartel fue capturado']);
        $r3 = $this->createRecord(['contexto' => 'Resultado del partido de fútbol']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'Juan Pérez',
                    'cargo' => 'Ministro de Economía',
                    'categoria' => 'PEP',
                    'confianza' => 95,
                    'motivo' => 'Cargo ejecutivo de alto nivel',
                ]))
                ->push($this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'Rodrigo Vargas',
                    'cargo' => null,
                    'categoria' => 'OPI',
                    'confianza' => 92,
                    'motivo' => 'Líder de organización criminal',
                ]))
                ->push($this->fakeGeminiResponse([
                    'is_pep' => false,
                    'nombre' => null,
                    'cargo' => null,
                    'categoria' => null,
                    'confianza' => 10,
                    'motivo' => 'Texto deportivo sin relevancia',
                ])),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$r1, $r2, $r3]));

        $r1->refresh();
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertTrue($r1->gemini_is_pep);
        $this->assertSame('Juan Pérez', $r1->gemini_nombre);
        $this->assertSame('Ministro de Economía', $r1->gemini_cargo);
        $this->assertSame('PEP', $r1->gemini_categoria);
        $this->assertSame(95, $r1->gemini_confianza);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertTrue($r2->gemini_is_pep);
        $this->assertSame('Rodrigo Vargas', $r2->gemini_nombre);
        $this->assertNull($r2->gemini_cargo);
        $this->assertSame('OPI', $r2->gemini_categoria);
        $this->assertSame(92, $r2->gemini_confianza);

        $r3->refresh();
        $this->assertTrue($r3->gemini_analyzed);
        $this->assertFalse($r3->gemini_is_pep);
        $this->assertNull($r3->gemini_nombre);
        $this->assertNull($r3->gemini_cargo);
        $this->assertNull($r3->gemini_categoria);
        $this->assertSame(10, $r3->gemini_confianza);
    }

    public function test_invalid_json_response_marks_record_analyzed_and_continues(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $r1 = $this->createRecord(['contexto' => 'Record 1']);
        $r2 = $this->createRecord(['contexto' => 'Record 2']);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response($this->fakeGeminiResponse([
                        'is_pep' => false,
                        'nombre' => null,
                        'cargo' => null,
                        'categoria' => null,
                        'confianza' => 5,
                        'motivo' => 'No relevante',
                    ]), 200);
                }

                // Second call returns non-JSON text (invalid response body from API)
                return Http::response('esto no es json valido', 200);
            },
        ]);

        $service = $this->makeService();

        // Should NOT throw — invalid response is caught, marked analyzed, logged, continues
        $service->analizarLote(collect([$r1, $r2]));

        $r1->refresh();
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertFalse($r1->gemini_is_pep);
        $this->assertSame(5, $r1->gemini_confianza);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertNull($r2->gemini_is_pep);
        $this->assertNull($r2->gemini_confianza);
    }

    public function test_bad_request_exception_marks_record_analyzed_and_continues(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $r1 = $this->createRecord(['contexto' => 'Record 1']);
        $r2 = $this->createRecord(['contexto' => 'Record 2']);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response($this->fakeGeminiResponse([
                        'is_pep' => false,
                        'nombre' => null,
                        'cargo' => null,
                        'categoria' => null,
                        'confianza' => 10,
                        'motivo' => 'Nada relevante',
                    ]), 200);
                }

                return Http::response(['error' => 'Invalid prompt'], 400);
            },
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$r1, $r2]));

        $r1->refresh();
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertSame(10, $r1->gemini_confianza);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertNull($r2->gemini_is_pep);
    }

    public function test_rate_limit_exception_bubbles_up(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $service = $this->makeService();

        $this->expectException(GeminiRateLimitException::class);
        $service->analizarLote(collect([$record]));
    }

    public function test_server_exception_bubbles_up(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Internal'], 500),
        ]);

        $service = $this->makeService();

        $this->expectException(GeminiServerException::class);
        $service->analizarLote(collect([$record]));
    }

    public function test_non_retryable_exception_does_not_stop_other_records(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $r1 = $this->createRecord(['contexto' => 'Record 1']);
        $r2 = $this->createRecord(['contexto' => 'Record 2']);
        $r3 = $this->createRecord(['contexto' => 'Record 3']);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;

                return match ($callCount) {
                    1 => Http::response($this->fakeGeminiResponse([
                        'is_pep' => true,
                        'nombre' => 'Persona 1',
                        'cargo' => 'Ministro',
                        'categoria' => 'PEP',
                        'confianza' => 90,
                        'motivo' => 'Alto cargo',
                    ]), 200),
                    2 => Http::response('invalid response body', 200), // bad JSON
                    3 => Http::response($this->fakeGeminiResponse([
                        'is_pep' => false,
                        'nombre' => null,
                        'cargo' => null,
                        'categoria' => null,
                        'confianza' => 5,
                        'motivo' => 'No relevante',
                    ]), 200),
                    default => Http::response([], 500),
                };
            },
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$r1, $r2, $r3]));

        $r1->refresh();
        $this->assertTrue($r1->gemini_is_pep);
        $this->assertSame(90, $r1->gemini_confianza);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertNull($r2->gemini_is_pep);  // failed, no data

        $r3->refresh();
        $this->assertTrue($r3->gemini_analyzed);
        $this->assertFalse($r3->gemini_is_pep);  // processed correctly
        $this->assertSame(5, $r3->gemini_confianza);
    }
}
