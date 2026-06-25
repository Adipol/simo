<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Services\Gemini\DTOs\GeminiResponseDTO;
use Tests\TestCase;

/**
 * Unit tests for GeminiResponseDTO::thinkingTokens().
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Covers: present value, absent key, null usageMetadata.
 */
class GeminiResponseDTOThinkingTokensTest extends TestCase
{
    // =========================================================================
    // RED 1 — returns the int value when thoughtsTokenCount is present
    // =========================================================================

    public function test_thinking_tokens_returns_int_when_thoughts_token_count_is_present(): void
    {
        $dto = new GeminiResponseDTO(
            content: [],
            usageMetadata: [
                'promptTokenCount'     => 100,
                'candidatesTokenCount' => 50,
                'thoughtsTokenCount'   => 800,
                'totalTokenCount'      => 950,
            ],
        );

        $this->assertSame(800, $dto->thinkingTokens());
    }

    // =========================================================================
    // Triangulation — returns null when thoughtsTokenCount key is absent
    // =========================================================================

    public function test_thinking_tokens_returns_null_when_thoughts_token_count_is_absent(): void
    {
        $dto = new GeminiResponseDTO(
            content: [],
            usageMetadata: [
                'promptTokenCount'     => 100,
                'candidatesTokenCount' => 50,
                'totalTokenCount'      => 150,
                // No thoughtsTokenCount
            ],
        );

        $this->assertNull($dto->thinkingTokens());
    }

    // =========================================================================
    // Triangulation — returns null when usageMetadata is null
    // =========================================================================

    public function test_thinking_tokens_returns_null_when_usage_metadata_is_null(): void
    {
        $dto = new GeminiResponseDTO(
            content: [],
            usageMetadata: null,
        );

        $this->assertNull($dto->thinkingTokens());
    }
}
