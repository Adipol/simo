<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Exceptions\Gemini\GeminiServerException;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Services\Gemini\GeminiAnalisisService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiAnalisisServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createFuente(array $overrides = []): Fuente
    {
        return Fuente::create(array_merge([
            'url' => 'https://gobierno.bo/ministerio-economia',
            'nombre' => 'Ministerio de Economía',
            'organismo' => 'Ministerio de Economía y Finanzas Públicas',
            'pais' => 'BO',
        ], $overrides));
    }

    private function createCambio(Fuente $fuente, array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'diff_texto' => "-Dr. Carlos Méndez\n+Lic. Ana García\n Director de Planificación",
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

    private function makeService(): GeminiAnalisisService
    {
        return new GeminiAnalisisService(
            new GeminiService(apiKey: 'test-key-123'),
            new GeminiPromptBuilder,
        );
    }

    public function test_analizar_lote_populates_gemini_analisis_json(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse([
                'persona_removida' => 'Dr. Carlos Méndez',
                'persona_nueva' => 'Lic. Ana García',
                'cargo' => 'Ministro de Economía',
                'es_mae' => true,
                'riesgo' => 'alto',
                'analisis' => 'Cambio de MAE detectado: nuevo Ministro de Economía.',
            ]), 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambio]));

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
        $this->assertNotNull($cambio->gemini_analisis_json);

        $json = $cambio->gemini_analisis_json;
        $this->assertSame('Dr. Carlos Méndez', $json['persona_removida']);
        $this->assertSame('Lic. Ana García', $json['persona_nueva']);
        $this->assertSame('Ministro de Economía', $json['cargo']);
        $this->assertTrue($json['es_mae']);
        $this->assertSame('alto', $json['riesgo']);
        $this->assertSame('Cambio de MAE detectado: nuevo Ministro de Economía.', $json['analisis']);
    }

    public function test_large_diff_uses_truncar_diff(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();

        // Create a diff > 15000 chars to trigger branch 3 (extract +/- lines)
        $contextLine = str_repeat('x', 200);
        $lines = [];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = " {$contextLine}";
        }
        $lines[] = '-Persona Removida del cargo de director general';
        $lines[] = '+Persona Nueva en el cargo de director general';
        for ($i = 0; $i < 500; $i++) {
            $lines[] = " {$contextLine}";
        }
        $largeDiff = implode("\n", $lines);

        $cambio = $this->createCambio($fuente, ['diff_texto' => $largeDiff]);

        $capturedPrompt = '';
        Http::fake([
            'generativelanguage.googleapis.com/*' => function ($request) use (&$capturedPrompt) {
                $body = $request->data();
                $capturedPrompt = $body['contents'][0]['parts'][0]['text'] ?? '';

                return Http::response($this->fakeGeminiResponse([
                    'persona_removida' => 'Persona Removida',
                    'persona_nueva' => 'Persona Nueva',
                    'cargo' => 'Director General',
                    'es_mae' => true,
                    'riesgo' => 'alto',
                    'analisis' => 'Cambio de MAE detectado.',
                ]), 200);
            },
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambio]));

        // Verify truncation happened: original 200K+ chars prompt should be reduced
        // The truncated diff should contain the +/- lines but not all context lines
        $this->assertLessThan(strlen($largeDiff), strlen($capturedPrompt));
        $this->assertStringContainsString('-Persona Removida', $capturedPrompt);
        $this->assertStringContainsString('+Persona Nueva', $capturedPrompt);

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
        $this->assertNotNull($cambio->gemini_analisis_json);
        $this->assertSame('alto', $cambio->gemini_analisis_json['riesgo']);
    }

    public function test_invalid_json_marks_record_analyzed_and_continues(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $c1 = $this->createCambio($fuente);
        $c2 = $this->createCambio($fuente);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response($this->fakeGeminiResponse([
                        'persona_removida' => null,
                        'persona_nueva' => null,
                        'cargo' => null,
                        'es_mae' => false,
                        'riesgo' => 'bajo',
                        'analisis' => 'Sin cambios relevantes.',
                    ]), 200);
                }

                return Http::response('not valid json at all', 200);
            },
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$c1, $c2]));

        $c1->refresh();
        $this->assertTrue($c1->gemini_analyzed);
        $this->assertNotNull($c1->gemini_analisis_json);
        $this->assertSame('bajo', $c1->gemini_analisis_json['riesgo']);

        $c2->refresh();
        $this->assertTrue($c2->gemini_analyzed);
        $this->assertNull($c2->gemini_analisis_json);  // failed, null
    }

    public function test_rate_limit_exception_bubbles_up(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $service = $this->makeService();

        $this->expectException(GeminiRateLimitException::class);
        $service->analizarLote(collect([$cambio]));
    }

    public function test_server_exception_bubbles_up(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Internal'], 500),
        ]);

        $service = $this->makeService();

        $this->expectException(GeminiServerException::class);
        $service->analizarLote(collect([$cambio]));
    }

    public function test_bad_request_does_not_stop_other_records(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $c1 = $this->createCambio($fuente);
        $c2 = $this->createCambio($fuente);

        $callCount = 0;
        Http::fake([
            'generativelanguage.googleapis.com/*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response(['error' => 'Bad request'], 400);
                }

                return Http::response($this->fakeGeminiResponse([
                    'persona_removida' => null,
                    'persona_nueva' => 'Lic. Nuevo',
                    'cargo' => 'Subdirector',
                    'es_mae' => false,
                    'riesgo' => 'bajo',
                    'analisis' => 'Nuevo subdirector asignado.',
                ]), 200);
            },
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$c1, $c2]));

        $c1->refresh();
        $this->assertTrue($c1->gemini_analyzed);
        $this->assertNull($c1->gemini_analisis_json);  // bad request, null

        $c2->refresh();
        $this->assertTrue($c2->gemini_analyzed);
        $this->assertNotNull($c2->gemini_analisis_json);
        $this->assertSame('Lic. Nuevo', $c2->gemini_analisis_json['persona_nueva']);
    }
}
