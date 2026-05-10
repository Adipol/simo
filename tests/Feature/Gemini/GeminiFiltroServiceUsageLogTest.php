<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for GeminiFiltroService — usage log + timestamp instrumentation.
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T68–T70 (Phase 11 — GeminiFiltroService instrumentation)
 *
 * Critical paths per spec:
 * - Happy path: timestamp written + usage_log row inserted
 * - Error path: no timestamp, no usage_log row (state unchanged)
 * - Missing usageMetadata: null tokens, log warning, analysis NOT aborted
 * - Idempotency: re-running skips already-analyzed records
 */
class GeminiFiltroServiceUsageLogTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url'              => 'https://example.com/article-' . uniqid(),
            'keyword'          => 'corrupcion',
            'pais'             => 'BO',
            'categoria'        => 'politica',
            'titulo'           => 'Ministro firma decreto',
            'contexto'         => 'El ministro de Economía Juan Pérez firmó un decreto importante.',
            'relevance_score'  => 80,
            'gemini_analyzed'  => false,
            'gemini_analyzed_at' => null,
        ], $overrides));
    }

    private function fakeGeminiResponseWithUsage(array $content, int $promptTokens = 80, int $completionTokens = 40): string
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
            // No usageMetadata
        ]);
    }

    private function successfulFiltroContent(): array
    {
        return [
            'personas' => [[
                'nombre'       => 'Juan Pérez',
                'cargo'        => 'Ministro de Economía',
                'categoria'    => 'PEP',
                'entidad_tipo' => 'publica',
                'confianza'    => 90,
                'evento'       => 'designacion',
                'motivo'       => 'Alto cargo ejecutivo',
            ]],
            'motivo_general' => 'Artículo sobre acción ministerial',
        ];
    }

    private function makeService(): GeminiFiltroService
    {
        return new GeminiFiltroService(
            new GeminiService(apiKey: 'test-key-123'),
            new GeminiPromptBuilder,
        );
    }

    // =========================================================================
    // T68 — Happy path: timestamp + usage_log row written
    // =========================================================================

    public function test_happy_path_writes_timestamp_and_usage_log(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponseWithUsage($this->successfulFiltroContent(), 80, 40),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$record]));

        $record->refresh();

        // Timestamp must be set
        $this->assertNotNull($record->gemini_analyzed_at);
        $this->assertTrue($record->gemini_analyzed);

        // usage_log row linked to this resultado_scraping_id
        $log = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('resultado_scraping_id', $record->id)
            ->first();

        $this->assertNotNull($log, 'gemini_usage_log must have a row for this record');
        $this->assertSame(80, $log->prompt_tokens);
        $this->assertSame(40, $log->completion_tokens);
        $this->assertSame(120, $log->total_tokens);
        $this->assertSame('filtro', $log->request_type);
        $this->assertNull($log->cambio_id);
    }

    // =========================================================================
    // T69 — Error path: no timestamp, no usage_log row
    // =========================================================================

    public function test_error_path_does_not_write_timestamp_or_usage_log(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord();

        // Simulate invalid JSON → caught by marcarFallido
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('invalido para filtro', 200),
        ]);

        $this->makeService()->analizarLote(collect([$record]));

        $record->refresh();

        // Timestamp must NOT be set
        $this->assertNull($record->gemini_analyzed_at);

        $count = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('resultado_scraping_id', $record->id)
            ->count();

        $this->assertSame(0, $count, 'gemini_usage_log must NOT have rows on error');
    }

    // =========================================================================
    // T70 — Missing usageMetadata: null tokens, warning logged, NOT aborted
    // =========================================================================

    public function test_missing_usagemetadata_persists_with_null_tokens_and_logs_warning(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $record = $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponseWithoutUsage($this->successfulFiltroContent()),
                200
            ),
        ]);

        $warningLogged = false;

        Log::listen(function (\Illuminate\Log\Events\MessageLogged $event) use (&$warningLogged): void {
            if ($event->level === 'warning' && str_contains($event->message, 'usageMetadata')) {
                $warningLogged = true;
            }
        });

        $this->makeService()->analizarLote(collect([$record]));

        $record->refresh();

        // Analysis must succeed
        $this->assertNotNull($record->gemini_analyzed_at);
        $this->assertTrue($record->gemini_analyzed);

        $log = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('resultado_scraping_id', $record->id)
            ->first();

        $this->assertNotNull($log, 'usage_log row must be inserted even without usageMetadata');
        $this->assertNull($log->prompt_tokens);
        $this->assertNull($log->completion_tokens);
        $this->assertNull($log->total_tokens);

        // Warning must be logged
        $this->assertTrue($warningLogged, 'A warning about missing usageMetadata must be logged');
    }

    // =========================================================================
    // Idempotency — already-analyzed record is skipped
    // =========================================================================

    public function test_idempotency_skips_already_analyzed_record(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        // Already analyzed: gemini_analyzed=true + gemini_analyzed_at set
        $record = $this->createRecord([
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => now()->subMinutes(10),
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponseWithUsage($this->successfulFiltroContent()),
                200
            ),
        ]);

        $this->makeService()->analizarLote(collect([$record]));

        // HTTP must NOT have been called
        Http::assertNothingSent();

        $count = \Illuminate\Support\Facades\DB::table('gemini_usage_log')
            ->where('resultado_scraping_id', $record->id)
            ->count();

        $this->assertSame(0, $count, 'idempotency: already-analyzed record must not generate usage_log rows');
    }
}
