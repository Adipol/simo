<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\Gemini\GeminiConnectionException;
use App\Exceptions\Gemini\GeminiException;
use App\Exceptions\Gemini\GeminiImageReadException;
use Tests\TestCase;

/**
 * Validates config keys and exception class contracts for the timeout-fragility fix.
 */
class GeminiTimeoutConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Task 1.1 — Config keys
    // -------------------------------------------------------------------------

    public function test_config_timeout_key_is_45(): void
    {
        $this->assertSame(45, config('services.gemini.timeout'));
    }

    public function test_config_multimodal_timeout_key_exists_and_is_60(): void
    {
        $this->assertSame(60, config('services.gemini.multimodal_timeout'));
    }

    // -------------------------------------------------------------------------
    // Task 1.3 — GeminiImageReadException is Tier-1 (permanent, not transient)
    // -------------------------------------------------------------------------

    public function test_gemini_image_read_exception_class_exists(): void
    {
        $this->assertTrue(class_exists(GeminiImageReadException::class));
    }

    public function test_gemini_image_read_exception_extends_gemini_exception(): void
    {
        $e = new GeminiImageReadException('/tmp/fake.png');
        $this->assertInstanceOf(GeminiException::class, $e);
        $this->assertInstanceOf(\Exception::class, $e);
    }

    public function test_gemini_image_read_exception_is_not_transient(): void
    {
        // Tier-2 transients extend GeminiRateLimitException or GeminiServerException.
        // ImageRead must NOT extend those — it is a permanent per-record error.
        $e = new GeminiImageReadException('/tmp/fake.png');
        $this->assertNotInstanceOf(\App\Exceptions\Gemini\GeminiRateLimitException::class, $e);
        $this->assertNotInstanceOf(\App\Exceptions\Gemini\GeminiServerException::class, $e);
    }

    // -------------------------------------------------------------------------
    // Task 1.5 — GeminiConnectionException is Tier-2 (transient wrapper)
    // -------------------------------------------------------------------------

    public function test_gemini_connection_exception_class_exists(): void
    {
        $this->assertTrue(class_exists(GeminiConnectionException::class));
    }

    public function test_gemini_connection_exception_extends_gemini_exception(): void
    {
        $e = new GeminiConnectionException('timeout');
        $this->assertInstanceOf(GeminiException::class, $e);
        $this->assertInstanceOf(\Exception::class, $e);
    }

    public function test_gemini_connection_exception_is_not_permanent(): void
    {
        // Tier-1 permanent errors: ImageRead, BadRequest, InvalidResponse.
        // ConnectionException must NOT extend those — it is a transient/retryable error.
        $e = new GeminiConnectionException('timeout');
        $this->assertNotInstanceOf(GeminiImageReadException::class, $e);
        $this->assertNotInstanceOf(\App\Exceptions\Gemini\GeminiBadRequestException::class, $e);
        $this->assertNotInstanceOf(\App\Exceptions\Gemini\GeminiInvalidResponseException::class, $e);
    }
}
