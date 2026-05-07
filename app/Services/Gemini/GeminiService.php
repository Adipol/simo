<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\Gemini\GeminiApiKeyMissingException;
use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiException;
use App\Exceptions\Gemini\GeminiImageReadException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Exceptions\Gemini\GeminiPayloadTooLargeException;
use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Exceptions\Gemini\GeminiServerException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $baseUrl;

    public function __construct(
        private ?string $apiKey = null,
        private ?int $timeout = null,
    ) {
        $this->apiKey = $apiKey ?? config('services.gemini.api_key');
        $this->baseUrl = config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta/models/');
        $this->timeout = $timeout ?? (int) config('services.gemini.timeout', 90);
    }

    /**
     * Send a prompt to Gemini API and return the parsed JSON response.
     *
     * @param  string  $prompt  The text prompt to send
     * @param  string  $model  The model identifier (e.g., 'gemini-1.5-flash')
     * @return array The parsed JSON response from Gemini
     *
     * @throws GeminiApiKeyMissingException If API key is not configured
     * @throws GeminiRateLimitException On HTTP 429
     * @throws GeminiBadRequestException On HTTP 400
     * @throws GeminiServerException On HTTP 500+ or connection timeout
     * @throws GeminiInvalidResponseException If response is not valid JSON
     */
    public function send(string $prompt, string $model): array
    {
        if (empty($this->apiKey)) {
            throw new GeminiApiKeyMissingException('Gemini API key is not configured. Set GEMINI_API_KEY in your .env file.');
        }

        $url = "{$this->baseUrl}{$model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout($this->timeout)
            ->when(app()->environment('local'), fn ($http) => $http->withoutVerifying())
            ->post($url, $this->buildRequestBody($prompt));

        if ($response->failed()) {
            $this->handleError($response->status(), $response->body());
        }

        $responseData = $response->json();

        if (! is_array($responseData)) {
            Log::channel('gemini')->error('Gemini returned non-JSON response', [
                'model' => $model,
                'raw_response' => $response->body(),
            ]);

            throw new GeminiInvalidResponseException(
                'Gemini returned non-JSON response body.'
            );
        }

        $text = $this->extractText($responseData);

        $parsed = json_decode($this->cleanMarkdownJson($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::channel('gemini')->error('Gemini returned invalid JSON', [
                'model' => $model,
                'raw_response' => $text,
                'json_error' => json_last_error_msg(),
            ]);

            throw new GeminiInvalidResponseException(
                'Gemini returned invalid JSON: '.json_last_error_msg()
            );
        }

        return $parsed;
    }

    /**
     * Send a prompt + images to Gemini Vision API (multimodal) and return the parsed JSON response.
     *
     * @param  array<int,array{path:string,mime_type:string}>  $imagenes  Absolute filesystem paths
     * @return array The parsed JSON response from Gemini
     *
     * @throws GeminiApiKeyMissingException If API key is not configured
     * @throws GeminiPayloadTooLargeException If total payload size exceeds configured limit
     * @throws GeminiRateLimitException On HTTP 429
     * @throws GeminiBadRequestException On HTTP 400
     * @throws GeminiServerException On HTTP 500+ or connection timeout
     * @throws GeminiInvalidResponseException If response is not valid JSON
     */
    public function sendMultimodal(string $prompt, array $imagenes, string $model): array
    {
        if (empty($this->apiKey)) {
            throw new GeminiApiKeyMissingException('Gemini API key is not configured. Set GEMINI_API_KEY in your .env file.');
        }

        $maxBytes = (int) config('services.gemini.multimodal_max_payload_bytes', 100 * 1024 * 1024);
        $totalSize = 0;

        foreach ($imagenes as $img) {
            $totalSize += filesize($img['path']);
        }

        if ($totalSize > $maxBytes) {
            throw new GeminiPayloadTooLargeException($totalSize, $maxBytes);
        }

        $url = "{$this->baseUrl}{$model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout($this->timeout)
            ->when(app()->environment('local'), fn ($http) => $http->withoutVerifying())
            ->post($url, $this->buildRequestBodyMultimodal($prompt, $imagenes));

        if ($response->failed()) {
            $this->handleError($response->status(), $response->body());
        }

        $responseData = $response->json();

        if (! is_array($responseData)) {
            Log::channel('gemini')->error('Gemini returned non-JSON response (multimodal)', [
                'model' => $model,
                'image_count' => count($imagenes),
                'raw_response' => $response->body(),
            ]);

            throw new GeminiInvalidResponseException(
                'Gemini returned non-JSON response body.'
            );
        }

        $text = $this->extractText($responseData);

        $parsed = json_decode($this->cleanMarkdownJson($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::channel('gemini')->error('Gemini returned invalid JSON (multimodal)', [
                'model' => $model,
                'image_count' => count($imagenes),
                'raw_response' => $text,
                'json_error' => json_last_error_msg(),
            ]);

            throw new GeminiInvalidResponseException(
                'Gemini returned invalid JSON: '.json_last_error_msg()
            );
        }

        return $parsed;
    }

    /**
     * Build the request body for Gemini API.
     */
    private function buildRequestBody(string $prompt): array
    {
        return [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the multimodal request body for Gemini Vision API.
     *
     * @param  array<int,array{path:string,mime_type:string}>  $imagenes
     */
    private function buildRequestBodyMultimodal(string $prompt, array $imagenes): array
    {
        $parts = [['text' => $prompt]];

        foreach ($imagenes as $img) {
            $bytes = file_get_contents($img['path']);

            if ($bytes === false) {
                throw new GeminiImageReadException($img['path']);
            }

            $parts[] = [
                'inline_data' => [
                    'mime_type' => $img['mime_type'],
                    'data' => base64_encode($bytes),
                ],
            ];
        }

        return [
            'contents' => [
                ['parts' => $parts],
            ],
        ];
    }

    /**
     * Extract the text content from Gemini response.
     *
     * @throws GeminiInvalidResponseException If structure is invalid
     */
    private function extractText(array $response): string
    {
        if (! isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            throw new GeminiInvalidResponseException(
                'Gemini response missing expected structure: candidates[0].content.parts[0].text'
            );
        }

        return $response['candidates'][0]['content']['parts'][0]['text'];
    }

    /**
     * Map HTTP error status to appropriate exception.
     *
     * @throws GeminiException Always
     */
    private function handleError(int $status, string $body): never
    {
        throw match (true) {
            $status === 429 => new GeminiRateLimitException("Gemini rate limit exceeded (429): {$body}"),
            $status === 400 => new GeminiBadRequestException("Gemini bad request (400): {$body}"),
            $status >= 500 => new GeminiServerException("Gemini server error ({$status}): {$body}"),
            default => new GeminiException("Gemini API error ({$status}): {$body}"),
        };
    }

    /**
     * Clean markdown code blocks from JSON response.
     */
    private function cleanMarkdownJson(string $text): string
    {
        // Remove ```json ... ``` or ``` ...``` wrappers
        $text = preg_replace('/^```(?:json)?\s*\n?/i', '', $text);
        $text = preg_replace('/\n?```\s*$/i', '', $text);

        return trim($text);
    }
}
