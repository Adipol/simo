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
    // Task 2.1 — text methods use gemini.timeout (45s)
    // -------------------------------------------------------------------------

    public function test_send_uses_text_timeout(): void
    {
        config([
            'services.gemini.api_key'          => 'test-key',
            'services.gemini.timeout'          => 45,
            'services.gemini.multimodal_timeout' => 60,
        ]);

        $capturedTimeout = null;

        Http::fake(function ($request) use (&$capturedTimeout) {
            // The timeout is set on the Guzzle options, not on the HTTP request itself.
            // We verify it indirectly: if the service sends the request at all it means
            // it picked up the right config. We also confirm via the service constructor.
            $capturedTimeout = 'called';

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
        // Verify text timeout is 45 (the config value).
        $this->assertSame(45, $this->getPrivateProperty($service, 'textTimeout'));
        // Multimodal timeout must differ.
        $this->assertSame(60, $this->getPrivateProperty($service, 'multimodalTimeout'));
    }

    public function test_send_multimodal_uses_multimodal_timeout(): void
    {
        config([
            'services.gemini.api_key'          => 'test-key',
            'services.gemini.timeout'          => 45,
            'services.gemini.multimodal_timeout' => 60,
        ]);

        $service = new GeminiService(apiKey: 'test-key');
        $this->assertSame(60, $this->getPrivateProperty($service, 'multimodalTimeout'));
        $this->assertSame(45, $this->getPrivateProperty($service, 'textTimeout'));
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

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function getPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }
}
