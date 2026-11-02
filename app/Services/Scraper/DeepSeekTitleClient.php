<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\DTOs\TitleGateResult;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use JsonException;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class DeepSeekTitleClient
{
    public const ENDPOINT = 'https://api.deepseek.com/chat/completions';
    public const PROMPT_VERSION = '9d6de7068451427546b895d9c6727d603e771c001e5e6815b2d8dc6186da1ae4';
    public const MAX_RESPONSE_BYTES = 262144;

    public function __construct(private readonly DeepSeekTitleSettings $settings) {}

    public function classify(int $id, string $originalTitle): TitleGateResult
    {
        if (! $this->settings->configured()) {
            return $this->failure('not_configured');
        }
        try {
            $prompt = File::get(resource_path('prompts/scraper-title-gate.txt'));
        } catch (FileNotFoundException) {
            return $this->failure('prompt_unavailable');
        }
        if (hash('sha256', $prompt) !== self::PROMPT_VERSION) {
            return $this->failure('prompt_unavailable');
        }
        try {
            $input = json_encode(['id' => $id, 'titulo' => $originalTitle], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failure('invalid_input');
        }

        try {
            $response = Http::withToken($this->settings->apiKey())->acceptJson()
                ->connectTimeout($this->settings->timeout)->timeout($this->settings->timeout)
                ->withOptions([
                    'allow_redirects' => false, 'stream' => true, 'read_timeout' => $this->settings->timeout,
                    // Reject declared oversized bodies before transfer, then bound streamed reads below.
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((float) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
                            throw new LengthException('Response limit');
                        }
                    },
                ])->post(self::ENDPOINT, [
                    'model' => $this->settings->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user', 'content' => $input],
                    ],
                    'thinking' => ['type' => 'disabled'], 'response_format' => ['type' => 'json_object'],
                    'stream' => false, 'max_tokens' => 6500,
                ]);
            $stream = $response->toPsrResponse()->getBody();
            try {
                if ($response->status() !== 200) {
                    return $this->failure('http_'.$response->status());
                }
                $body = '';
                $deadline = microtime(true) + $this->settings->timeout;
                while (! $stream->eof()) {
                    if (microtime(true) > $deadline) {
                        return $this->failure('transport_error');
                    }
                    $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
                    if ($chunk === '' && ! $stream->eof()) {
                        return $this->failure('transport_error');
                    }
                    $body .= $chunk;
                    if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                        return $this->failure('invalid_response', true);
                    }
                }
            } finally {
                $stream->close();
            }
        } catch (ConnectionException|RequestException|TransferException|RuntimeException $exception) {
            // Header guards may be wrapped by the HTTP transport; inspect types, never messages.
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof LengthException) {
                    return $this->failure('invalid_response', true);
                }
            }
            // Never persist or log exception messages, provider bodies, headers or credentials.
            return $this->failure('transport_error');
        }

        try {
            $envelope = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            if (! $envelope instanceof \stdClass || ! isset($envelope->choices) || ! is_array($envelope->choices)
                || count($envelope->choices) !== 1 || ! $envelope->choices[0] instanceof \stdClass
                || ($envelope->choices[0]->finish_reason ?? null) !== 'stop'
                || ! isset($envelope->choices[0]->message) || ! $envelope->choices[0]->message instanceof \stdClass
                || ! is_string($envelope->choices[0]->message->content ?? null)
                || ! is_string($envelope->model ?? null) || preg_match('/\A[a-zA-Z0-9._-]{1,128}\z/', $envelope->model) !== 1) {
                return $this->failure('invalid_response', true);
            }
            $decoded = json_decode($envelope->choices[0]->message->content, false, 16, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass || array_keys(get_object_vars($decoded)) !== ['resultados']
                || ! is_array($decoded->resultados) || count($decoded->resultados) !== 1
                || ! $decoded->resultados[0] instanceof \stdClass) {
                return $this->failure('invalid_response', true);
            }
            return TitleGateResult::fromArray(get_object_vars($decoded->resultados[0]), $id, $originalTitle, $envelope->model, self::PROMPT_VERSION)
                ?? $this->failure('invalid_response', true);
        } catch (JsonException) {
            return $this->failure('invalid_response', true);
        }
    }

    private function failure(string $code, bool $invalidResponse = false): TitleGateResult
    {
        return TitleGateResult::failure($code, self::PROMPT_VERSION, $invalidResponse);
    }
}
