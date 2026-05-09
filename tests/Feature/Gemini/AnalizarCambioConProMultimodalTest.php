<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Jobs\AnalizarCambioConPro;
use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalizarCambioConProMultimodalTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.enabled' => true,
            'services.gemini.multimodal_enabled' => true,
            'services.gemini.multimodal_max_payload_bytes' => 100 * 1024 * 1024,
        ]);
    }

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

    private function createFuente(): Fuente
    {
        // Default: analizar_imagenes=true para que los tests del job que esperan
        // rama multimodal funcionen. Tests text-only no se ven afectados (ya están
        // creando cambios sin imágenes).
        return Fuente::create([
            'url' => 'https://gobierno.bo/ente',
            'nombre' => 'Ente Gubernamental',
            'organismo' => 'Ente Gubernamental del Estado',
            'pais' => 'BO',
            'analizar_imagenes' => true,
        ]);
    }

    private function createCambio(Fuente $fuente, array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'diff_texto' => "-Persona Vieja\n+Persona Nueva",
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function createImageFile(string $relPath, int $sizeBytes = 256): string
    {
        $absPath = storage_path('app/' . $relPath);
        @mkdir(dirname($absPath), 0777, true);
        file_put_contents($absPath, str_repeat('E', $sizeBytes));
        $this->tempFiles[] = $absPath;

        return $absPath;
    }

    private function fakeGeminiResponse(): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'persona_removida' => 'Persona Vieja',
                            'persona_nueva' => 'Persona Nueva',
                            'cargo' => 'Director',
                            'es_mae' => false,
                            'riesgo' => 'medio',
                            'analisis' => 'Cambio detectado.',
                        ]),
                    ]],
                ],
            ]],
        ]);
    }

    // ============================================
    // Task 6.1 / 6.2 — job handles mixed batch
    // ============================================

    public function test_job_processes_mixed_batch_with_and_without_images(): void
    {
        $relPath = 'img_cambios/job_test.png';
        $this->createImageFile($relPath);

        $fuente = $this->createFuente();

        $cambioConImagenes = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [
                ['path' => $relPath, 'mime_type' => 'image/png'],
            ],
        ]);

        $cambioSinImagenes = $this->createCambio($fuente, [
            'imagenes_cambio_json' => null,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse(), 200),
        ]);

        (new AnalizarCambioConPro)->handle();

        $cambioConImagenes->refresh();
        $cambioSinImagenes->refresh();

        $this->assertTrue($cambioConImagenes->gemini_analyzed);
        $this->assertTrue($cambioSinImagenes->gemini_analyzed);
        $this->assertNotNull($cambioConImagenes->gemini_analisis_json);
        $this->assertNotNull($cambioSinImagenes->gemini_analisis_json);
    }

    /**
     * Race condition test: If imagenes_cambio_json arrives via UPDATE after the job is dispatched,
     * the job reads the FINAL state from DB (since it re-queries in handle()).
     * If the UPDATE happened before handle() runs, it picks up multimodal.
     * If not, it processes text-only — both are acceptable (graceful degradation).
     */
    public function test_job_reads_final_state_of_cambio_when_images_arrive_before_handle(): void
    {
        $relPath = 'img_cambios/race_condition_test.png';
        $this->createImageFile($relPath);

        $fuente = $this->createFuente();

        // Step 1: CREATE cambio without images (simulates INSERT before Python UPDATE)
        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => null,
        ]);

        // Step 2: UPDATE imagenes_cambio_json BEFORE job runs (simulates fast UPDATE)
        Cambio::where('id', $cambio->id)->update([
            'imagenes_cambio_json' => json_encode([
                ['path' => $relPath, 'mime_type' => 'image/png'],
            ]),
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse(), 200),
        ]);

        // Job dispatched after UPDATE — reads updated state
        (new AnalizarCambioConPro)->handle();

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
        $this->assertNotNull($cambio->gemini_analisis_json);

        // The job should have used multimodal (images were there when job ran)
        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) >= 2 && isset($parts[1]['inline_data']);
        });
    }

    public function test_job_processes_text_only_when_update_arrives_late(): void
    {
        $fuente = $this->createFuente();

        // CREATE without images, no subsequent UPDATE — simulates race condition where UPDATE is late
        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => null,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse(), 200),
        ]);

        // Job runs BEFORE the UPDATE arrives — processes text-only (graceful degradation)
        (new AnalizarCambioConPro)->handle();

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
        $this->assertNotNull($cambio->gemini_analisis_json);

        // Text-only path: only 1 part
        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) === 1 && ! isset($parts[1]);
        });
    }

    // ============================================
    // Task 6.3 / 6.4 — kill switch: multimodal_enabled=false
    // ============================================

    public function test_job_uses_text_only_when_multimodal_disabled_even_with_images(): void
    {
        config(['services.gemini.multimodal_enabled' => false]);

        $relPath = 'img_cambios/disabled_test.png';
        $this->createImageFile($relPath);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [
                ['path' => $relPath, 'mime_type' => 'image/png'],
            ],
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeGeminiResponse(), 200),
        ]);

        (new AnalizarCambioConPro)->handle();

        // Should have sent text-only request (no inline_data)
        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) === 1 && ! isset($parts[1]);
        });

        $cambio->refresh();
        $this->assertTrue($cambio->gemini_analyzed);
    }

    // ============================================
    // Task 6.3 — backoff preserved
    // ============================================

    public function test_job_has_correct_backoff_values(): void
    {
        $job = new AnalizarCambioConPro;

        $this->assertSame([5, 25, 125], $job->backoff);
        $this->assertSame(3, $job->tries);
    }
}
