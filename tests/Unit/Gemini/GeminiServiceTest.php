<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Exceptions\Gemini\GeminiApiKeyMissingException;
use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Exceptions\Gemini\GeminiServerException;
use App\Services\Gemini\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    private const API_KEY = 'test-api-key-12345';

    private function validGeminiResponse(): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'is_pep' => true,
                            'nombre' => 'Juan Pérez',
                            'cargo' => 'Ministro',
                            'categoria' => 'PEP',
                            'confianza' => 95,
                            'motivo' => 'Cargo ejecutivo',
                        ]),
                    ]],
                ],
            ]],
        ]);
    }

    public function test_send_returns_parsed_array_on_success(): void
    {
        config([
            'services.gemini.api_key' => self::API_KEY,
            'services.gemini.timeout' => 30,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiResponse(), 200),
        ]);

        $service = new GeminiService;
        $result = $service->send('Test prompt', 'gemini-1.5-flash');

        $this->assertIsArray($result);
        $this->assertTrue($result['is_pep']);
        $this->assertSame('Juan Pérez', $result['nombre']);
    }

    public function test_send_uses_correct_model_in_url(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiResponse(), 200),
        ]);

        $service = new GeminiService;
        $service->send('Test', 'gemini-1.5-pro');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gemini-1.5-pro:generateContent');
        });
    }

    public function test_send_throws_rate_limit_exception_on_429(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $service = new GeminiService;

        $this->expectException(GeminiRateLimitException::class);
        $service->send('Test', 'gemini-1.5-flash');
    }

    public function test_send_throws_bad_request_exception_on_400(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Bad request'], 400),
        ]);

        $service = new GeminiService;

        $this->expectException(GeminiBadRequestException::class);
        $service->send('Test', 'gemini-1.5-flash');
    }

    public function test_send_throws_server_exception_on_500(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Server error'], 500),
        ]);

        $service = new GeminiService;

        $this->expectException(GeminiServerException::class);
        $service->send('Test', 'gemini-1.5-flash');
    }

    public function test_send_throws_server_exception_on_503(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Unavailable'], 503),
        ]);

        $service = new GeminiService;

        $this->expectException(GeminiServerException::class);
        $service->send('Test', 'gemini-1.5-flash');
    }

    public function test_send_throws_invalid_response_on_non_json(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('This is plain text, not JSON', 200),
        ]);

        $service = new GeminiService;

        $this->expectException(GeminiInvalidResponseException::class);
        $service->send('Test', 'gemini-1.5-flash');
    }

    public function test_send_throws_invalid_response_on_missing_candidates(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'empty'], 200),
        ]);

        $service = new GeminiService;

        $this->expectException(GeminiInvalidResponseException::class);
        $service->send('Test', 'gemini-1.5-flash');
    }

    public function test_send_throws_api_key_missing_when_config_null(): void
    {
        config(['services.gemini.api_key' => null]);

        $service = new GeminiService;

        $this->expectException(GeminiApiKeyMissingException::class);
        $service->send('Test', 'gemini-1.5-flash');
    }

    public function test_send_includes_api_key_in_url(): void
    {
        config(['services.gemini.api_key' => self::API_KEY]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->validGeminiResponse(), 200),
        ]);

        $service = new GeminiService;
        $service->send('Test', 'gemini-1.5-flash');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'key='.self::API_KEY);
        });
    }
}
