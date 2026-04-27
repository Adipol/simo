<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Enums\EntidadTipo;
use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Exceptions\Gemini\GeminiServerException;
use App\Models\CargoPep;
use App\Models\EntidadPublica;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use App\Services\Gemini\PepCatalogService;
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
     * Wraps inner data (already in new multi-persona format) inside the Gemini API envelope.
     */
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

    private function makeServiceWithCatalog(): GeminiFiltroService
    {
        return new GeminiFiltroService(
            new GeminiService(apiKey: 'test-key-123'),
            app(GeminiPromptBuilder::class),
        );
    }

    public function test_analizar_lote_updates_all_gemini_fields_on_happy_path(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $r1 = $this->createRecord(['contexto' => 'Ministro Juan Pérez firmó decreto']);
        $r2 = $this->createRecord(['contexto' => 'El fiscal general imputó al líder del cartel capturado']);
        $r3 = $this->createRecord(['contexto' => 'El ministro de deportes asistió al partido de fútbol']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->fakeGeminiResponse([
                    'personas' => [[
                        'nombre' => 'Juan Pérez',
                        'cargo' => 'Ministro de Economía',
                        'categoria' => 'PEP',
                        'entidad_tipo' => 'publica',
                        'confianza' => 95,
                        'evento' => 'designacion',
                        'motivo' => 'Cargo ejecutivo de alto nivel',
                    ]],
                    'motivo_general' => 'Artículo sobre acción ministerial',
                ]))
                ->push($this->fakeGeminiResponse([
                    'personas' => [[
                        'nombre' => 'Rodrigo Vargas',
                        'cargo' => null,
                        'categoria' => 'OPI',
                        'entidad_tipo' => 'desconocido',
                        'confianza' => 92,
                        'evento' => 'crimen',
                        'motivo' => 'Líder de organización criminal',
                    ]],
                    'motivo_general' => 'Captura de líder criminal',
                ]))
                ->push($this->fakeGeminiResponse([
                    'personas' => [],
                    'motivo_general' => 'Texto deportivo sin relevancia',
                ])),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$r1, $r2, $r3]));

        $r1->refresh();
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertTrue($r1->gemini_is_pep);
        $this->assertSame('Artículo sobre acción ministerial', $r1->gemini_motivo);

        $p1 = ResultadoPersona::where('resultado_scraping_id', $r1->id)->first();
        $this->assertNotNull($p1);
        $this->assertSame('Juan Pérez', $p1->nombre);
        $this->assertSame('Ministro de Economía', $p1->cargo);
        $this->assertSame('PEP', $p1->categoria);
        $this->assertSame('publica', $p1->entidad_tipo);
        $this->assertSame(95, $p1->confianza);
        $this->assertTrue($p1->threshold_passed);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertTrue($r2->gemini_is_pep);
        $this->assertSame('Captura de líder criminal', $r2->gemini_motivo);

        $p2 = ResultadoPersona::where('resultado_scraping_id', $r2->id)->first();
        $this->assertNotNull($p2);
        $this->assertSame('Rodrigo Vargas', $p2->nombre);
        $this->assertNull($p2->cargo);
        $this->assertSame('OPI', $p2->categoria);
        $this->assertSame('desconocido', $p2->entidad_tipo);
        $this->assertSame(92, $p2->confianza);
        $this->assertTrue($p2->threshold_passed);

        $r3->refresh();
        $this->assertTrue($r3->gemini_analyzed);
        $this->assertFalse($r3->gemini_is_pep);
        $this->assertSame('Texto deportivo sin relevancia', $r3->gemini_motivo);
        $this->assertSame(0, ResultadoPersona::where('resultado_scraping_id', $r3->id)->count());
    }

    public function test_invalid_json_response_marks_record_analyzed_and_continues(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $r1 = $this->createRecord(['contexto' => 'El presidente designó Record 1']);
        $r2 = $this->createRecord(['contexto' => 'El fiscal general presentó Record 2']);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response($this->fakeGeminiResponse([
                        'personas' => [],
                        'motivo_general' => 'No relevante',
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

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertFalse($r2->gemini_is_pep, 'gemini_is_pep must be false (not null) after failure');
    }

    public function test_bad_request_exception_marks_record_analyzed_and_continues(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $r1 = $this->createRecord(['contexto' => 'El presidente designó Record 1']);
        $r2 = $this->createRecord(['contexto' => 'El fiscal general presentó Record 2']);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response($this->fakeGeminiResponse([
                        'personas' => [],
                        'motivo_general' => 'Nada relevante',
                    ]), 200);
                }

                return Http::response(['error' => 'Invalid prompt'], 400);
            },
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$r1, $r2]));

        $r1->refresh();
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertFalse($r1->gemini_is_pep);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertFalse($r2->gemini_is_pep, 'gemini_is_pep must be false (not null) after failure');
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

        $r1 = $this->createRecord(['contexto' => 'El presidente designó Record 1']);
        $r2 = $this->createRecord(['contexto' => 'El fiscal general presentó Record 2']);
        $r3 = $this->createRecord(['contexto' => 'El ministro anunció Record 3']);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;

                return match ($callCount) {
                    1 => Http::response($this->fakeGeminiResponse([
                        'personas' => [[
                            'nombre' => 'Persona 1',
                            'cargo' => 'Ministro',
                            'categoria' => 'PEP',
                            'entidad_tipo' => 'publica',
                            'confianza' => 90,
                            'evento' => 'designacion',
                            'motivo' => 'Alto cargo',
                        ]],
                        'motivo_general' => 'Artículo sobre Persona 1',
                    ]), 200),
                    2 => Http::response('invalid response body', 200), // bad JSON
                    3 => Http::response($this->fakeGeminiResponse([
                        'personas' => [],
                        'motivo_general' => 'No relevante',
                    ]), 200),
                    default => Http::response([], 500),
                };
            },
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$r1, $r2, $r3]));

        $r1->refresh();
        $this->assertTrue($r1->gemini_is_pep);
        $p1 = ResultadoPersona::where('resultado_scraping_id', $r1->id)->first();
        $this->assertNotNull($p1);
        $this->assertSame(90, $p1->confianza);
        $this->assertTrue($p1->threshold_passed);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertFalse($r2->gemini_is_pep, 'gemini_is_pep must be false (not null) after failure');  // failed, no data
        $this->assertSame(0, ResultadoPersona::where('resultado_scraping_id', $r2->id)->count());

        $r3->refresh();
        $this->assertTrue($r3->gemini_analyzed);
        $this->assertFalse($r3->gemini_is_pep);  // processed correctly, no personas
        $this->assertSame(0, ResultadoPersona::where('resultado_scraping_id', $r3->id)->count());
    }

    public function test_dynamic_prompt_is_used_when_catalog_has_positions_for_country(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        // Flush static cache so PepCatalogService reads from DB fresh
        PepCatalogService::flushCache();

        // Seed minimal Bolivia dataset: 3 positions (1 cada tipo) + 1 entidad
        CargoPep::create([
            'pais_codigo' => 'BO',
            'nombre' => 'Ministro Titular',
            'categoria' => 'A',
            'entidad_tipo' => EntidadTipo::Todas,
            'activo' => true,
        ]);

        CargoPep::create([
            'pais_codigo' => 'BO',
            'nombre' => 'Director Ejecutivo Público',
            'categoria' => 'A',
            'entidad_tipo' => EntidadTipo::Publica,
            'activo' => true,
        ]);

        CargoPep::create([
            'pais_codigo' => 'BO',
            'nombre' => 'Gerente General Mixto',
            'categoria' => 'A',
            'entidad_tipo' => EntidadTipo::Ambas,
            'activo' => true,
        ]);

        EntidadPublica::create([
            'pais_codigo' => 'BO',
            'nombre' => 'Banco Estatal Test',
            'sigla' => 'BET',
            'activo' => true,
        ]);

        $record = $this->createRecord(['pais' => 'BO']);

        $capturedPrompt = null;

        Http::fake([
            'generativelanguage.googleapis.com/*' => function ($request) use (&$capturedPrompt) {
                $body = $request->data();
                $capturedPrompt = $body['contents'][0]['parts'][0]['text'] ?? null;

                return Http::response(json_encode([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'text' => json_encode([
                                    'personas' => [],
                                    'motivo_general' => 'Test',
                                ]),
                            ]],
                        ],
                    ]],
                ]), 200);
            },
        ]);

        $service = $this->makeServiceWithCatalog();
        $service->analizarLote(collect([$record]));

        $this->assertNotNull($capturedPrompt, 'The prompt should have been captured from the HTTP request');
        $this->assertIsString($capturedPrompt);
        $this->assertStringContainsString('SIEMPRE_PEP', (string) $capturedPrompt);
        $this->assertStringContainsString('PEP_EN_ENTIDAD_PUBLICA', (string) $capturedPrompt);
        $this->assertStringContainsString('PUEDE_SER_PEP', (string) $capturedPrompt);
        $this->assertStringContainsString('Ministro Titular', (string) $capturedPrompt);
        $this->assertStringContainsString('Director Ejecutivo Público', (string) $capturedPrompt);
        $this->assertStringContainsString('Gerente General Mixto', (string) $capturedPrompt);
        $this->assertStringContainsString('Banco Estatal Test', (string) $capturedPrompt);
    }

    public function test_marcarFallido_persists_error_motivo(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord([
            'gemini_analyzed' => false,
            'gemini_is_pep' => null,
        ]);

        // Simulate a bad request (400) which triggers GeminiBadRequestException → marcarFallido
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Invalid prompt'], 400),
        ]);

        $geminiWarningCalled = false;
        $capturedContext = [];

        \Illuminate\Support\Facades\Log::shouldReceive('channel')
            ->with('gemini')
            ->andReturnSelf();

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$geminiWarningCalled, &$capturedContext) {
                if (array_key_exists('motivo', $context)) {
                    $geminiWarningCalled = true;
                    $capturedContext = $context;
                }

                return true;
            });

        \Illuminate\Support\Facades\Log::shouldReceive('info')->andReturnNull();

        $service = $this->makeService();
        $service->analizarLote(collect([$record]));

        $this->assertTrue($geminiWarningCalled, 'Log::warning must be called with a motivo key in context');
        $this->assertArrayHasKey('resultado_id', $capturedContext);
        $this->assertSame($record->id, $capturedContext['resultado_id']);
    }

    public function test_marcarFallido_persists_motivo_to_db(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord([
            'gemini_analyzed' => false,
            'gemini_is_pep' => null,
        ]);

        // Simulate a bad request (400) — triggers GeminiBadRequestException → marcarFallido
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Invalid prompt'], 400),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$record]));

        $record->refresh();
        $this->assertStringStartsWith('Gemini bad request (400):', (string) $record->gemini_error_motivo);
    }

    public function test_marcarFallido_persists_null_motivo_when_no_message(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord([
            'gemini_analyzed' => false,
            'gemini_is_pep' => null,
        ]);

        // Invalid JSON → GeminiInvalidResponseException with a non-null message
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('esto no es json valido', 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$record]));

        $record->refresh();
        // gemini_error_motivo should be set (not null) — the exception message is the motivo
        $this->assertNotNull($record->gemini_error_motivo);
        $this->assertIsString($record->gemini_error_motivo);
    }

    public function test_marcarFallido_sets_gemini_is_pep_false(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord([
            'gemini_analyzed' => false,
            'gemini_is_pep' => null,
        ]);

        // Simulate invalid JSON response that triggers marcarFallido
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('esto no es json valido', 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$record]));

        $record->refresh();
        $this->assertTrue($record->gemini_analyzed, 'gemini_analyzed must be true after failure');
        $this->assertFalse($record->gemini_is_pep, 'gemini_is_pep must be false (not null) after failure');
    }
}
