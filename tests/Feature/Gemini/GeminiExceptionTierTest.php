<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\Gemini\GeminiConnectionException;
use App\Exceptions\Gemini\GeminiImageReadException;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Services\Gemini\GeminiAnalisisService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for the 3-tier exception strategy in GeminiAnalisisService.
 *
 * Tier 1 (permanent/per-record): marcarFallido + continue — batch completes.
 * Tier 2 (transient/infra):      propagates — job retries, records NOT marcarFallido.
 * Tier 3 (programmer/config):    propagates uncaught — job fails loudly.
 */
class GeminiExceptionTierTest extends TestCase
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

    private function createFuente(array $overrides = []): Fuente
    {
        return Fuente::create(array_merge([
            'url' => 'https://gobierno.bo/test',
            'nombre' => 'Test Ministry',
            'organismo' => 'Test Organism',
            'pais' => 'BO',
            'analizar_imagenes' => true,
        ], $overrides));
    }

    private function createCambio(Fuente $fuente, array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'diff_texto' => "-Old Person\n+New Person\n Role change",
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function createReadableImageFile(string $filename): string
    {
        $absPath = storage_path('app/img_cambios/' . $filename);
        @mkdir(dirname($absPath), 0777, true);
        file_put_contents($absPath, str_repeat('X', 256));
        $this->tempFiles[] = $absPath;

        return 'img_cambios/' . $filename;
    }

    private function fakeGeminiResponse(array $data): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => json_encode($data)]],
                ],
            ]],
        ]);
    }

    private function validAnalisisData(): array
    {
        return [
            'persona_removida' => 'Old Person',
            'persona_nueva'    => 'New Person',
            'cargo'            => 'Director',
            'es_mae'           => false,
            'riesgo'           => 'bajo',
            'analisis'         => 'Role change detected.',
        ];
    }

    private function makeService(): GeminiAnalisisService
    {
        return new GeminiAnalisisService(
            new GeminiService(apiKey: 'test-key-123'),
            new GeminiPromptBuilder,
        );
    }

    // =========================================================================
    // Task 3.3 — Tier-1: GeminiImageReadException → marcarFallido + siblings continue
    // =========================================================================

    /**
     * When one multimodal record has an unreadable image (GeminiImageReadException),
     * only THAT record is marked failed; sibling records still process; batch completes.
     *
     * GeminiService is mocked to throw GeminiImageReadException on the multimodal call
     * (Tier-1). The sibling text-only cambio must still complete — the batch does not abort.
     */
    public function test_image_read_exception_marks_record_siblings_continue(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.multimodal_enabled' => true,
        ]);

        $fuente = $this->createFuente();

        // Mock GeminiService to throw GeminiImageReadException (Tier-1) on the multimodal call.
        $imageReadThrown = false;
        $geminiMock = $this->createMock(GeminiService::class);
        $callCount = 0;

        $geminiMock->method('sendMultimodalWithMetadata')
            ->willReturnCallback(function () use (&$callCount, &$imageReadThrown) {
                $callCount++;
                $imageReadThrown = true;
                throw new GeminiImageReadException('/fake/path.png');
            });

        $geminiMock->method('sendWithMetadata')
            ->willReturn(new \App\Services\Gemini\DTOs\GeminiResponseDTO(
                content: $this->validAnalisisData(),
                usageMetadata: null,
            ));

        // Create a cambio with images (will hit procesarCambioMultimodal)
        $relPath2 = $this->createReadableImageFile('sibling_test_' . uniqid() . '.png');
        $cambio1 = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [['path' => $relPath2, 'mime_type' => 'image/png']],
        ]);

        // Create a sibling cambio without images (will hit procesarCambio/sendWithMetadata)
        $cambio2 = $this->createCambio($fuente, [
            'imagenes_cambio_json' => null,
        ]);

        Http::fake(); // no real HTTP

        $service = new GeminiAnalisisService($geminiMock, new GeminiPromptBuilder);
        $service->analizarLote(collect([$cambio1, $cambio2]));

        // cambio1: GeminiImageReadException (Tier-1) → marcarFallido
        $cambio1->refresh();
        $this->assertTrue($cambio1->gemini_analyzed, 'Image-read failed record must be marked analyzed=true');
        $this->assertNotNull($cambio1->gemini_analyzed_at, 'marcarFallido must set gemini_analyzed_at');
        $this->assertNull($cambio1->gemini_analisis_json, 'Failed record must NOT have analisis_json');

        // cambio2: sibling must still be processed — batch did NOT abort
        $cambio2->refresh();
        $this->assertTrue($cambio2->gemini_analyzed, 'Sibling record must be analyzed after Tier-1 failure');
        $this->assertNotNull($cambio2->gemini_analisis_json, 'Sibling must have analisis_json');
    }

    // =========================================================================
    // Task 3.5 — marcarFallido sets gemini_analyzed_at
    // =========================================================================

    public function test_marcar_fallido_sets_gemini_analyzed_at(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        // Trigger Tier-1 (BadRequest → marcarFallido) via Http::fake
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Bad request'], 400),
        ]);

        $service = $this->makeService();
        $service->analizarLote(collect([$cambio]));

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
        $this->assertNotNull($cambio->gemini_analyzed_at, 'marcarFallido must set gemini_analyzed_at');
    }

    // =========================================================================
    // Task 3.8 — Tier-2: GeminiConnectionException propagates, records NOT marcarFallido
    // =========================================================================

    public function test_tier2_connection_exception_propagates_no_marcar_fallido(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        // Simulate GeminiConnectionException from GeminiService (Tier-2)
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout');
        });

        $service = $this->makeService();

        // Tier-2 must propagate (job will retry)
        $this->expectException(GeminiConnectionException::class);
        $service->analizarLote(collect([$cambio]));

        // Record must NOT be marcarFallido (it should stay gemini_analyzed=false)
        // NOTE: this assertion only runs if the exception is NOT thrown — but since
        // we expectException(), the test verifies propagation. The post-throw state
        // is validated by the DB refresh in the next test.
    }

    public function test_tier2_connection_exception_does_not_mark_record_failed(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $service = $this->makeService();

        try {
            $service->analizarLote(collect([$cambio]));
        } catch (GeminiConnectionException) {
            // Expected: Tier-2 propagates
        }

        // Record must remain pending — NOT marcarFallido
        $cambio->refresh();
        $this->assertFalse($cambio->gemini_analyzed, 'Tier-2 transient must NOT mark record as analyzed');
        $this->assertNull($cambio->gemini_analyzed_at, 'Tier-2 transient must NOT set analyzed_at');
    }
}
