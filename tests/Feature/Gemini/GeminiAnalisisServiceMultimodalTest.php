<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Services\Gemini\GeminiAnalisisService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiAnalisisServiceMultimodalTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function createTempImage(string $filename, int $sizeBytes = 512): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, str_repeat('A', $sizeBytes));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function createFuente(array $overrides = []): Fuente
    {
        return Fuente::create(array_merge([
            'url' => 'https://gobierno.bo/ministerio',
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
            'diff_texto' => "-Dr. Carlos Méndez\n+Lic. Ana García",
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

    private function geminiAnalisisData(array $overrides = []): array
    {
        return array_merge([
            'persona_removida' => 'Dr. Carlos Méndez',
            'persona_nueva' => 'Lic. Ana García',
            'cargo' => 'Ministro de Economía',
            'es_mae' => true,
            'riesgo' => 'alto',
            'analisis' => 'Cambio de MAE detectado.',
        ], $overrides);
    }

    // ============================================
    // Task 5.1 / 5.2 — procesarCambioMultimodal invokes sendMultimodal
    // ============================================

    public function test_analizar_lote_calls_send_multimodal_when_cambio_tiene_imagenes(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.multimodal_enabled' => true]);

        $imagePath = $this->createTempImage('organigrama.png', 512);
        // Path relativo que PHP resuelve via storage_path
        $relPath = 'img_cambios/organigrama_test.png';
        // We store the file in the temp location — the JSON stores the relative path
        // resolverImagenes will call storage_path('app/'.$relPath) to get the absolute path
        // So we need the file at storage_path('app/'.$relPath)
        $absPath = storage_path('app/' . $relPath);
        @mkdir(dirname($absPath), 0777, true);
        file_put_contents($absPath, str_repeat('B', 512));
        $this->tempFiles[] = $absPath;

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [
                ['path' => $relPath, 'mime_type' => 'image/png'],
            ],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse($this->geminiAnalisisData()), 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambio]));

        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];
            // Must have inline_data part (multimodal request)
            return count($parts) >= 2 && isset($parts[1]['inline_data']);
        });

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
        $this->assertNotNull($cambio->gemini_analisis_json);
    }

    // ============================================
    // Task 5.3 / 5.4 — response parsed and persisted
    // ============================================

    public function test_processar_cambio_multimodal_persists_dto_correctly(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.multimodal_enabled' => true]);

        $relPath = 'img_cambios/test_persist.png';
        $absPath = storage_path('app/' . $relPath);
        @mkdir(dirname($absPath), 0777, true);
        file_put_contents($absPath, str_repeat('C', 256));
        $this->tempFiles[] = $absPath;

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [
                ['path' => $relPath, 'mime_type' => 'image/png'],
            ],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse([
                'persona_removida' => 'Ministro Saliente',
                'persona_nueva' => 'Ministro Entrante',
                'cargo' => 'Ministro de Planificación',
                'es_mae' => true,
                'riesgo' => 'alto',
                'analisis' => 'Análisis desde imagen del organigrama.',
            ]), 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambio]));

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);

        $json = $cambio->gemini_analisis_json;
        $this->assertSame('Ministro Saliente', $json['persona_removida']);
        $this->assertSame('Ministro Entrante', $json['persona_nueva']);
        $this->assertSame('Ministro de Planificación', $json['cargo']);
        $this->assertTrue($json['es_mae']);
        $this->assertSame('alto', $json['riesgo']);
    }

    // ============================================
    // Task 5.5 / 5.6 — no regression: cambio sin imágenes → procesarCambio
    // ============================================

    public function test_analizar_lote_uses_text_only_when_cambio_no_tiene_imagenes(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.multimodal_enabled' => true]);

        $fuente = $this->createFuente();
        // Cambio without images
        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => null,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse($this->geminiAnalisisData()), 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambio]));

        // Text-only path: parts should have only 1 part (text, no inline_data)
        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) === 1 && isset($parts[0]['text']) && ! isset($parts[1]);
        });

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
    }

    public function test_analizar_lote_handles_mixed_batch_correctly(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.multimodal_enabled' => true]);

        $relPath = 'img_cambios/mixed_batch.png';
        $absPath = storage_path('app/' . $relPath);
        @mkdir(dirname($absPath), 0777, true);
        file_put_contents($absPath, str_repeat('D', 256));
        $this->tempFiles[] = $absPath;

        $fuente = $this->createFuente();

        // One cambio with images, one without
        $cambioConImagenes = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [
                ['path' => $relPath, 'mime_type' => 'image/png'],
            ],
        ]);

        $cambioSinImagenes = $this->createCambio($fuente, [
            'imagenes_cambio_json' => null,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse($this->geminiAnalisisData()), 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambioConImagenes, $cambioSinImagenes]));

        $cambioConImagenes->refresh();
        $cambioSinImagenes->refresh();

        $this->assertTrue($cambioConImagenes->gemini_analyzed);
        $this->assertTrue($cambioSinImagenes->gemini_analyzed);
        $this->assertNotNull($cambioConImagenes->gemini_analisis_json);
        $this->assertNotNull($cambioSinImagenes->gemini_analisis_json);
    }

    public function test_analizar_lote_degrades_to_text_only_when_all_images_unreadable(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.multimodal_enabled' => true]);

        $fuente = $this->createFuente();
        // Images point to non-existent files
        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [
                ['path' => 'img_cambios/nonexistent_file.png', 'mime_type' => 'image/png'],
            ],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse($this->geminiAnalisisData()), 200),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambio]));

        // Should fall back to text-only (no inline_data parts)
        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) === 1 && ! isset($parts[1]);
        });

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
    }
}
