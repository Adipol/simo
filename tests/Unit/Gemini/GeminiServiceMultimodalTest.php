<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Exceptions\Gemini\GeminiPayloadTooLargeException;
use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Services\Gemini\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceMultimodalTest extends TestCase
{
    private const API_KEY = 'test-api-key-multimodal';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.api_key' => self::API_KEY,
            'services.gemini.timeout' => 30,
            'services.gemini.multimodal_max_payload_bytes' => 100 * 1024 * 1024, // 100MB
        ]);
    }

    private function validGeminiAnalisisResponse(): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'persona_removida' => 'Juan Pérez',
                            'persona_nueva' => 'Ana García',
                            'cargo' => 'Ministro',
                            'es_mae' => true,
                            'riesgo' => 'alto',
                            'analisis' => 'Cambio de MAE detectado.',
                        ]),
                    ]],
                ],
            ]],
        ]);
    }

    /** @var string[] */
    private array $tempFiles = [];

    private function createTempImage(string $filename, int $sizeBytes = 1024): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, str_repeat('A', $sizeBytes));
        $this->tempFiles[] = $path;

        return $path;
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

    // ============================================
    // Task 4.1 / 4.2 — sendMultimodal body shape
    // ============================================

    public function test_send_multimodal_posts_correct_shape_with_one_image(): void
    {
        $imagePath = $this->createTempImage('test_image.png', 1024);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiAnalisisResponse(), 200),
        ]);

        $service = new GeminiService;
        $service->sendMultimodal(
            'Analizar este diff',
            [['path' => $imagePath, 'mime_type' => 'image/png']],
            'gemini-2.5-flash',
        );

        Http::assertSent(function ($request) use ($imagePath) {
            $body = $request->data();

            // Must have contents with one item
            if (! isset($body['contents'][0]['parts'])) {
                return false;
            }

            $parts = $body['contents'][0]['parts'];

            // First part must be text
            if (! isset($parts[0]['text'])) {
                return false;
            }

            // Second part must be inline_data with mime_type and base64 data
            if (! isset($parts[1]['inline_data']['mime_type'])) {
                return false;
            }
            if (! isset($parts[1]['inline_data']['data'])) {
                return false;
            }

            // mime_type must match
            if ($parts[1]['inline_data']['mime_type'] !== 'image/png') {
                return false;
            }

            // data must be valid base64 of the file content
            $expectedBase64 = base64_encode(file_get_contents($imagePath));

            return $parts[1]['inline_data']['data'] === $expectedBase64;
        });
    }

    public function test_send_multimodal_uses_vision_model_in_url(): void
    {
        $imagePath = $this->createTempImage('vision_test.png', 512);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiAnalisisResponse(), 200),
        ]);

        $service = new GeminiService;
        $service->sendMultimodal(
            'Test prompt',
            [['path' => $imagePath, 'mime_type' => 'image/png']],
            'gemini-2.5-pro',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gemini-2.5-pro:generateContent');
        });
    }

    public function test_send_multimodal_includes_multiple_images_as_separate_parts(): void
    {
        $img1 = $this->createTempImage('img1.png', 512);
        $img2 = $this->createTempImage('img2.jpg', 512);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiAnalisisResponse(), 200),
        ]);

        $service = new GeminiService;
        $service->sendMultimodal(
            'Multi-image test',
            [
                ['path' => $img1, 'mime_type' => 'image/png'],
                ['path' => $img2, 'mime_type' => 'image/jpeg'],
            ],
            'gemini-2.5-flash',
        );

        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            // 1 text + 2 inline_data parts
            return count($parts) === 3
                && isset($parts[0]['text'])
                && isset($parts[1]['inline_data'])
                && isset($parts[2]['inline_data'])
                && $parts[1]['inline_data']['mime_type'] === 'image/png'
                && $parts[2]['inline_data']['mime_type'] === 'image/jpeg';
        });
    }

    public function test_send_multimodal_returns_parsed_array(): void
    {
        $imagePath = $this->createTempImage('return_test.png', 256);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiAnalisisResponse(), 200),
        ]);

        $service = new GeminiService;
        $result = $service->sendMultimodal(
            'Analizar',
            [['path' => $imagePath, 'mime_type' => 'image/png']],
            'gemini-2.5-flash',
        );

        $this->assertIsArray($result);
        $this->assertSame('Juan Pérez', $result['persona_removida']);
        $this->assertSame('alto', $result['riesgo']);
    }

    // ============================================
    // Task 4.3 / 4.4 — payload size guard
    // ============================================

    public function test_send_multimodal_throws_payload_too_large_when_over_100mb(): void
    {
        // Create a file that appears to be 101MB (we mock the size check)
        // We use a real file but configure a tiny limit so we can test without huge files
        config(['services.gemini.multimodal_max_payload_bytes' => 10]); // 10 bytes limit

        $imagePath = $this->createTempImage('large_image.png', 100); // 100 bytes > 10 byte limit

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiAnalisisResponse(), 200),
        ]);

        $service = new GeminiService;

        try {
            $service->sendMultimodal(
                'Test',
                [['path' => $imagePath, 'mime_type' => 'image/png']],
                'gemini-2.5-flash',
            );
            $this->fail('Expected GeminiPayloadTooLargeException to be thrown');
        } catch (GeminiPayloadTooLargeException $e) {
            // Now this assertion actually runs (after expectException it would not)
            Http::assertNothingSent();
        }
    }

    public function test_send_multimodal_does_not_throw_when_under_limit(): void
    {
        config(['services.gemini.multimodal_max_payload_bytes' => 1000]); // 1KB limit

        $imagePath = $this->createTempImage('small_image.png', 100); // 100 bytes < 1KB limit

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiAnalisisResponse(), 200),
        ]);

        $service = new GeminiService;

        // Should NOT throw
        $result = $service->sendMultimodal(
            'Test',
            [['path' => $imagePath, 'mime_type' => 'image/png']],
            'gemini-2.5-flash',
        );

        $this->assertIsArray($result);
    }

    // ============================================
    // Task 4.5 / 4.6 — HTTP error reuses handleError
    // ============================================

    public function test_send_multimodal_throws_rate_limit_on_429(): void
    {
        $imagePath = $this->createTempImage('rate_limit_test.png', 256);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $service = new GeminiService;

        $this->expectException(GeminiRateLimitException::class);

        $service->sendMultimodal(
            'Test',
            [['path' => $imagePath, 'mime_type' => 'image/png']],
            'gemini-2.5-flash',
        );
    }
}
