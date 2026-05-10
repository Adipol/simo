<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

/**
 * Wraps a Gemini API response: parsed inner content + raw usageMetadata.
 *
 * This DTO is returned by GeminiService::sendWithMetadata() and
 * ::sendMultimodalWithMetadata() to give calling services access to
 * token counts without changing the existing send() / sendMultimodal() API.
 */
final readonly class GeminiResponseDTO
{
    /**
     * @param  array  $content  Parsed inner JSON from candidates[0].content.parts[0].text
     * @param  array|null  $usageMetadata  Raw usageMetadata from the top-level response, or null if absent
     */
    public function __construct(
        public array $content,
        public ?array $usageMetadata,
    ) {}

    /**
     * Construct from a raw Gemini API response array (top-level responseData)
     * and the already-parsed inner content array.
     *
     * @param  array  $responseData  Full top-level API response (including usageMetadata if present)
     * @param  array  $parsedContent  Pre-parsed inner JSON content
     */
    public static function fromArray(array $responseData, array $parsedContent): self
    {
        $usageMetadata = is_array($responseData['usageMetadata'] ?? null)
            ? $responseData['usageMetadata']
            : null;

        return new self(
            content: $parsedContent,
            usageMetadata: $usageMetadata,
        );
    }

    public function promptTokens(): ?int
    {
        return isset($this->usageMetadata['promptTokenCount'])
            ? (int) $this->usageMetadata['promptTokenCount']
            : null;
    }

    public function completionTokens(): ?int
    {
        return isset($this->usageMetadata['candidatesTokenCount'])
            ? (int) $this->usageMetadata['candidatesTokenCount']
            : null;
    }

    public function totalTokens(): ?int
    {
        return isset($this->usageMetadata['totalTokenCount'])
            ? (int) $this->usageMetadata['totalTokenCount']
            : null;
    }

    public function hasUsageMetadata(): bool
    {
        return $this->usageMetadata !== null;
    }
}
