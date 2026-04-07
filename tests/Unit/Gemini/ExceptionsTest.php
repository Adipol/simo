<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Exceptions\Gemini\GeminiApiKeyMissingException;
use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Exceptions\Gemini\GeminiServerException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExceptionsTest extends TestCase
{
    public function test_gemini_exception_extends_runtime_exception(): void
    {
        $e = new GeminiException('test');
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function test_rate_limit_exception_extends_gemini_exception(): void
    {
        $e = new GeminiRateLimitException('rate limited');
        $this->assertInstanceOf(GeminiException::class, $e);
        $this->assertSame('rate limited', $e->getMessage());
    }

    public function test_bad_request_exception_extends_gemini_exception(): void
    {
        $e = new GeminiBadRequestException('bad request');
        $this->assertInstanceOf(GeminiException::class, $e);
    }

    public function test_server_exception_extends_gemini_exception(): void
    {
        $e = new GeminiServerException('server error');
        $this->assertInstanceOf(GeminiException::class, $e);
    }

    public function test_invalid_response_exception_extends_gemini_exception(): void
    {
        $e = new GeminiInvalidResponseException('invalid json');
        $this->assertInstanceOf(GeminiException::class, $e);
    }

    public function test_api_key_missing_exception_extends_gemini_exception(): void
    {
        $e = new GeminiApiKeyMissingException('no key');
        $this->assertInstanceOf(GeminiException::class, $e);
    }

    public function test_all_exceptions_carry_code_and_previous(): void
    {
        $previous = new \Exception('root cause');
        $e = new GeminiRateLimitException('rate limited', 429, $previous);

        $this->assertSame(429, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }
}
