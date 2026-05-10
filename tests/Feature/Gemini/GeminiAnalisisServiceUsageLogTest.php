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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for GeminiAnalisisService — usage log + timestamp instrumentation.
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T64–T67 (Phase 11 — GeminiAnalisisService instrumentation)
 *
 * Critical paths per spec:
 * - Happy path: timestamp written + usage_log row inserted
 * - Error path: no timestamp, no usage_log row (state unchanged)
 * - Missing usageMetadata: null tokens, log warning, analysis NOT aborted
 * - Idempotency: re-running skips already-analyzed cambios
 */
class GeminiAnalisisServiceUsageLogTest extends TestCase
{
    use RefreshDatabase;

    private function createFuente(): Fuente
    {
        return Fuente::create([
            'url'       => 'https://gobierno.bo/test',
            'nombre'    => 'Gobierno Test',
            'organismo' => 'Gobierno',
            'pais'      => 'BO',
        ]);
    }

    private function createCambio(Fuente $fuente, array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id'        => $fuente->id,
            'fecha'            => now()->subHour(),
            'diff_texto'       => "-Dr. Carlos Méndez\n+Lic. Ana García\n Director",
            'gemini_analyzed'  => false,
            'gemini_analyzed_at' => null,
        ], $overrides));
    }

    /**
     * Build a fake Gemini API response WITH usageMetadata.
     */
    private function fakeGeminiResponseWithUsage(array $content, int $promptTokens = 100, int $completionTokens = 50): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($content),
                    ]],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount'     => $promptTokens,
                'candidatesTokenCount' => $completionTokens,
                'totalTokenCount'      => $promptTokens + $completionTokens,
            ],
        ]);
    }

    /**
     * Build a fake Gemini API response WITHOUT usageMetadata.
     */
    private function fakeGeminiResponseWithoutUsage(array $content): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($content),
                    ]],
                ],
            ]],
            // No usageMetadata key — simulates responses that omit it
        ]);
    }

    private function successfulAnalysisContent(): array
    {
        return [
            'persona_removida'     => 'Dr. Carlos Méndez',
            'persona_nueva'        => 'Lic. Ana García',
            'cargo'                => 'Director',
            'es_mae'               => false,
            'riesgo'               => 'alto',
            'analisis'             => 'Cambio de director detectado.',
            'personas_detectadas'  => [],
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
    // T64 — Happy path: timestamp + usage_log row written
    // =========================================================================

    public function test_happy_path_writes_timestamp_and_usage_log(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponseWithUsage($this->successfulAnalysisContent(), 150, 75),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$cambio]));

        $cambio->refresh();

        // gemini_analyzed_at must be set to a non-null timestamp
        $this->assertNotNull($cambio->gemini_analyzed_at);
        $this->assertTrue($cambio->gemini_analyzed);

        // usage_log must have one row linked to this cambio
        $log = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('cambio_id', $cambio->id)
            ->first();

        $this->assertNotNull($log, 'gemini_usage_log must have a row for this cambio');
        $this->assertSame(150, $log->prompt_tokens);
        $this->assertSame(75, $log->completion_tokens);
        $this->assertSame(225, $log->total_tokens);
        $this->assertSame('analisis_cambio', $log->request_type);
    }

    // =========================================================================
    // T65 — Error path: no timestamp, no usage_log row
    // =========================================================================

    public function test_error_path_does_not_write_timestamp_or_usage_log(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        // Simulate a bad JSON response → GeminiInvalidResponseException (caught by marcarFallido)
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('no es json valido para gemini', 200),
        ]);

        $this->makeService()->analizarLote(collect([$cambio]));

        $cambio->refresh();

        // Timestamp must NOT be set
        $this->assertNull($cambio->gemini_analyzed_at);

        // No usage_log row
        $count = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('cambio_id', $cambio->id)
            ->count();

        $this->assertSame(0, $count, 'gemini_usage_log must NOT have rows when analysis fails');
    }

    // =========================================================================
    // T66 — Missing usageMetadata: null tokens, warning logged, analysis NOT aborted
    // =========================================================================

    public function test_missing_usagemetadata_persists_with_null_tokens_and_logs_warning(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponseWithoutUsage($this->successfulAnalysisContent()),
                200
            ),
        ]);

        $warningLogged = false;

        Log::listen(function (\Illuminate\Log\Events\MessageLogged $event) use (&$warningLogged): void {
            if ($event->level === 'warning' && str_contains($event->message, 'usageMetadata')) {
                $warningLogged = true;
            }
        });

        $this->makeService()->analizarLote(collect([$cambio]));

        $cambio->refresh();

        // Analysis MUST succeed — timestamp written
        $this->assertNotNull($cambio->gemini_analyzed_at);
        $this->assertTrue($cambio->gemini_analyzed);

        // usage_log row inserted with null tokens
        $log = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('cambio_id', $cambio->id)
            ->first();

        $this->assertNotNull($log, 'usage_log row must be inserted even when usageMetadata is missing');
        $this->assertNull($log->prompt_tokens);
        $this->assertNull($log->completion_tokens);
        $this->assertNull($log->total_tokens);

        // Warning MUST be logged
        $this->assertTrue($warningLogged, 'A warning about missing usageMetadata must be logged');
    }

    // =========================================================================
    // T67 — Idempotency: already-analyzed cambio is skipped
    // =========================================================================

    public function test_idempotency_skips_already_analyzed_cambio(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $fuente = $this->createFuente();

        // Already analyzed cambio: gemini_analyzed=true + gemini_analyzed_at set
        $cambio = $this->createCambio($fuente, [
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => now()->subMinutes(5),
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponseWithUsage($this->successfulAnalysisContent()),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$cambio]));

        // HTTP must NOT have been called (no new request)
        Http::assertNothingSent();

        // No new usage_log rows
        $count = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('cambio_id', $cambio->id)
            ->count();

        $this->assertSame(0, $count, 'idempotency: already-analyzed cambio must not generate usage_log rows');
    }

    // =========================================================================
    // Triangulation: multimodal path also writes timestamp + usage_log
    // =========================================================================

    public function test_multimodal_path_writes_timestamp_and_usage_log(): void
    {
        config([
            'services.gemini.api_key'            => 'test-key',
            'services.gemini.multimodal_enabled' => true,
        ]);

        $fuente = $this->createFuente();
        $fuente->update(['analizar_imagenes' => true]);

        $cambio = $this->createCambio($fuente, [
            'imagenes_cambio_json' => [
                ['path' => 'screenshots/test.png', 'mime_type' => 'image/png'],
            ],
        ]);

        // Mock image file readable
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::put('screenshots/test.png', 'fake-image-data');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponseWithUsage($this->successfulAnalysisContent(), 200, 80),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$cambio]));

        $cambio->refresh();

        $this->assertNotNull($cambio->gemini_analyzed_at, 'multimodal path must set gemini_analyzed_at');

        $log = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('cambio_id', $cambio->id)
            ->first();

        // May be 'analisis_cambio' or 'analisis_multimodal' — both valid
        $this->assertNotNull($log, 'multimodal path must insert usage_log row');
        $this->assertContains($log->request_type, ['analisis_cambio', 'analisis_multimodal']);
    }
}
