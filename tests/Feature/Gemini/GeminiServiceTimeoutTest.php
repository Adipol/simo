<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\Gemini\GeminiConnectionException;
use App\Services\Gemini\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Validates per-method timeout selection and ConnectionException wrapping in GeminiService.
 */
class GeminiServiceTimeoutTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Task 2.2 — Swap detection: text vs multimodal timeout on the actual HTTP call
    //
    // These tests capture the resolved Guzzle 'timeout' option from the Http::fake
    // callback. Laravel's buildStubHandler passes the full merged options (including
    // 'timeout') as the second argument to each stub closure, so $options['timeout']
    // reflects exactly what Http::timeout($n) set. A swap of textTimeout/multimodalTimeout
    // in either method body will make the captured value fail the assertSame below.
    // -------------------------------------------------------------------------

    /**
     * Verify send() passes the text timeout (45s) as the Guzzle 'timeout' option.
     *
     * The Http::fake callback receives ($request, $options) where $options['timeout']
     * is the resolved value from Http::timeout($this->textTimeout). If a developer
     * swaps textTimeout/multimodalTimeout in send(), the captured value becomes 60
     * and this test fails — genuine swap detection without reflection.
     */
    public function test_send_applies_textTimeout_not_multimodalTimeout(): void
    {
        config([
            'services.gemini.api_key'            => 'test-key',
            'services.gemini.timeout'            => 45,
            'services.gemini.multimodal_timeout' => 60,
        ]);

        $capturedTimeout = null;

        Http::fake(function ($request, array $options) use (&$capturedTimeout) {
            $capturedTimeout = $options['timeout'] ?? null;

            return Http::response(json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'persona_removida' => null,
                        'persona_nueva'    => null,
                        'cargo'            => null,
                        'es_mae'           => false,
                        'riesgo'           => 'bajo',
                        'analisis'         => 'ok',
                    ])]]],
                ]],
            ]), 200);
        });

        $service = new GeminiService(apiKey: 'test-key');
        $service->send('test prompt', 'gemini-test-model');

        // The real Guzzle timeout must be the text timeout (45s), not the multimodal (60s).
        // A swap of $this->textTimeout and $this->multimodalTimeout in send() makes this RED.
        $this->assertSame(45, $capturedTimeout,
            'send() must pass textTimeout (45s) to Http::timeout() — got ' . var_export($capturedTimeout, true));
        Http::assertSentCount(1);
    }

    /**
     * Verify sendMultimodal() passes the multimodal timeout (60s) as the Guzzle 'timeout' option.
     *
     * A swap of textTimeout/multimodalTimeout in sendMultimodal() causes the captured
     * value to be 45 instead of 60, making this test fail — genuine swap detection.
     */
    public function test_sendMultimodal_applies_multimodalTimeout_not_textTimeout(): void
    {
        config([
            'services.gemini.api_key'            => 'test-key',
            'services.gemini.timeout'            => 45,
            'services.gemini.multimodal_timeout' => 60,
        ]);

        $capturedTimeout = null;

        Http::fake(function ($request, array $options) use (&$capturedTimeout) {
            $capturedTimeout = $options['timeout'] ?? null;

            return Http::response(json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'persona_removida' => null,
                        'persona_nueva'    => null,
                        'cargo'            => null,
                        'es_mae'           => false,
                        'riesgo'           => 'bajo',
                        'analisis'         => 'ok',
                    ])]]],
                ]],
            ]), 200);
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'simo_swap_');
        file_put_contents($tmpFile, str_repeat('X', 100));

        try {
            $service = new GeminiService(apiKey: 'test-key');
            $service->sendMultimodal('test prompt', [
                ['path' => $tmpFile, 'mime_type' => 'image/png'],
            ], 'gemini-test-model');
        } finally {
            @unlink($tmpFile);
        }

        // The real Guzzle timeout must be the multimodal timeout (60s), not the text (45s).
        // A swap of $this->textTimeout and $this->multimodalTimeout in sendMultimodal() makes this RED.
        $this->assertSame(60, $capturedTimeout,
            'sendMultimodal() must pass multimodalTimeout (60s) to Http::timeout() — got ' . var_export($capturedTimeout, true));
        Http::assertSentCount(1);
    }

    // -------------------------------------------------------------------------
    // Task 2.3 — ConnectionException is wrapped into GeminiConnectionException
    // -------------------------------------------------------------------------

    public function test_send_wraps_connection_exception(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.timeout' => 45,
        ]);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $service = new GeminiService(apiKey: 'test-key');

        $this->expectException(GeminiConnectionException::class);
        $service->send('test prompt', 'gemini-test-model');
    }

    public function test_send_with_metadata_wraps_connection_exception(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.timeout' => 45,
        ]);

        Http::fake(function () {
            throw new ConnectionException('Operation timed out');
        });

        $service = new GeminiService(apiKey: 'test-key');

        $this->expectException(GeminiConnectionException::class);
        $service->sendWithMetadata('test prompt', 'gemini-test-model');
    }

    public function test_send_multimodal_wraps_connection_exception(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.timeout' => 45,
            'services.gemini.multimodal_timeout' => 60,
        ]);

        Http::fake(function () {
            throw new ConnectionException('Operation timed out');
        });

        // Create a readable temp file for the multimodal call
        $tmpFile = tempnam(sys_get_temp_dir(), 'simo_test_');
        file_put_contents($tmpFile, str_repeat('X', 100));

        try {
            $service = new GeminiService(apiKey: 'test-key');

            $this->expectException(GeminiConnectionException::class);
            $service->sendMultimodal('test prompt', [
                ['path' => $tmpFile, 'mime_type' => 'image/png'],
            ], 'gemini-test-model');
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_send_multimodal_with_metadata_wraps_connection_exception(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.timeout' => 45,
            'services.gemini.multimodal_timeout' => 60,
        ]);

        Http::fake(function () {
            throw new ConnectionException('Operation timed out');
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'simo_test_');
        file_put_contents($tmpFile, str_repeat('X', 100));

        try {
            $service = new GeminiService(apiKey: 'test-key');

            $this->expectException(GeminiConnectionException::class);
            $service->sendMultimodalWithMetadata('test prompt', [
                ['path' => $tmpFile, 'mime_type' => 'image/png'],
            ], 'gemini-test-model');
        } finally {
            @unlink($tmpFile);
        }
    }

}
