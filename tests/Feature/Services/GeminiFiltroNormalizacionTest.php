<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use App\Services\Normalization\NombreNormalizador;
use App\Services\Normalization\NombreNormalizadorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GeminiFiltroNormalizacionTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article',
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Ministro de Economía',
            'contexto' => 'El ministro firmó un decreto.',
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
            new NombreNormalizador,
        );
    }

    // ─── 5.1: Persist populates normalized column ─────────────────────────────

    public function test_persist_populates_gemini_nombre_normalizado_for_name_with_title(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        $record = $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'Dr. Juan Pérez',
                    'cargo' => 'Ministro',
                    'categoria' => 'PEP',
                    'entidad_tipo' => null,
                    'confianza' => 90,
                    'motivo' => 'Funcionario público',
                ]),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$record]));

        $record->refresh();
        $this->assertSame('Dr. Juan Pérez', $record->gemini_nombre);
        $this->assertSame('Juan Pérez', $record->gemini_nombre_normalizado);
    }

    public function test_persist_populates_normalized_for_all_caps_name(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        $record = $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'JUAN PÉREZ',
                    'cargo' => 'Senador',
                    'categoria' => 'PEP',
                    'entidad_tipo' => null,
                    'confianza' => 85,
                    'motivo' => 'Senador en ejercicio',
                ]),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$record]));

        $record->refresh();
        $this->assertSame('JUAN PÉREZ', $record->gemini_nombre);
        $this->assertSame('Juan Pérez', $record->gemini_nombre_normalizado);
    }

    // ─── 5.2: Null name propagation ───────────────────────────────────────────

    public function test_persist_sets_normalized_to_null_when_nombre_is_null(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        $record = $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => false,
                    'nombre' => null,
                    'cargo' => null,
                    'categoria' => null,
                    'entidad_tipo' => null,
                    'confianza' => 10,
                    'motivo' => 'No es PEP',
                ]),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$record]));

        $record->refresh();
        $this->assertNull($record->gemini_nombre);
        $this->assertNull($record->gemini_nombre_normalizado);
    }

    // ─── 5.3: Graceful failure ────────────────────────────────────────────────

    public function test_persist_continues_with_null_normalized_when_normalizer_throws(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        $record = $this->createRecord();

        // Mock normalizer that throws — use interface (NombreNormalizador is final)
        $mockNormalizador = $this->createMock(NombreNormalizadorInterface::class);
        $mockNormalizador->method('normalizeNullable')
            ->willThrowException(new \RuntimeException('Normalization failed'));

        $service = new GeminiFiltroService(
            new GeminiService(apiKey: 'test-key-123'),
            new GeminiPromptBuilder,
            $mockNormalizador,
        );

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'Dr. Juan Pérez',
                    'cargo' => 'Ministro',
                    'categoria' => 'PEP',
                    'entidad_tipo' => null,
                    'confianza' => 90,
                    'motivo' => 'Funcionario',
                ]),
                200
            ),
        ]);

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return str_contains($message, 'normalization') || str_contains($message, 'normalizaci');
        });
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();

        $service->analizarLote(collect([$record]));

        $record->refresh();
        // Persistence must continue
        $this->assertTrue($record->gemini_analyzed);
        // Normalized must be null (graceful failure)
        $this->assertNull($record->gemini_nombre_normalizado);
        // Original must be preserved
        $this->assertSame('Dr. Juan Pérez', $record->gemini_nombre);
    }

    // ─── 8.1: Full flow integration ───────────────────────────────────────────

    public function test_full_flow_analyze_persist_populates_normalized_column(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        $record = $this->createRecord(['contexto' => 'Artículo sobre el Dr. Pedro López']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'Dr. Pedro López',
                    'cargo' => 'Director General',
                    'categoria' => 'PEP',
                    'entidad_tipo' => null,
                    'confianza' => 92,
                    'motivo' => 'Director de entidad pública',
                ]),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$record]));

        $record->refresh();
        $this->assertSame('Dr. Pedro López', $record->gemini_nombre);
        $this->assertSame('Pedro López', $record->gemini_nombre_normalizado);
        $this->assertTrue($record->gemini_analyzed);
        $this->assertTrue($record->gemini_is_pep);
    }
}
