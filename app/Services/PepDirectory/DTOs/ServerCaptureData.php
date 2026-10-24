<?php

declare(strict_types=1);

namespace App\Services\PepDirectory\DTOs;

use App\Services\PepDirectory\ProcessPreviewCompiler;
use Illuminate\Validation\ValidationException;

/** INTERNAL adapter input. This type and provenance are NOT transport attestation. */
final readonly class ServerCaptureData
{
    private function __construct(public string $body, public array $metadata, public string $requestedUrl, public string $finalUrl, public array $provenance) {}

    public static function fromArray(array $input): self
    {
        $keys = ['raw_body', 'status', 'requested_url', 'final_url', 'captured_at', 'encoding', 'provenance'];
        self::check(count($input) === count($keys) && array_diff($keys, array_keys($input)) === []);
        self::check(is_string($input['raw_body']) && strlen($input['raw_body']) <= ProcessPreviewCompiler::MAX_BODY);
        self::check(is_int($input['status']) && $input['status'] >= 100 && $input['status'] <= 599);
        foreach (['requested_url', 'final_url'] as $key) {
            self::check(is_string($input[$key]) && strlen($input[$key]) <= 2048 && preg_match('~\Ahttps?://[^\s\x00-\x1f]+\z~D', $input[$key]) === 1 && filter_var($input[$key], FILTER_VALIDATE_URL) !== false);
        }
        self::check(is_string($input['encoding']) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $input['encoding']) === 1);
        self::check(is_array($input['provenance']) && array_keys($input['provenance']) === ['adapter']);
        self::check(is_string($input['provenance']['adapter']) && strlen($input['provenance']['adapter']) <= 255 && trim($input['provenance']['adapter']) !== '' && mb_check_encoding($input['provenance']['adapter'], 'UTF-8') && ! str_contains($input['provenance']['adapter'], "\0"));
        $time = $input['captured_at'];
        self::check(is_string($time) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/D', $time) === 1);
        try {
            $date = new \DateTimeImmutable($time);
            self::check($date::getLastErrors() === false && (int) $date->format('Y') > 0);
        } catch (\Exception) {
            throw ValidationException::withMessages(['capture' => 'Invalid aware capture time.']);
        }
        $observed = $date->format('Y-m-d\TH:i:s').($date->format('u') === '000000' ? '' : '.'.$date->format('u')).$date->format('P');
        return new self($input['raw_body'], ['status' => $input['status'], 'source_url' => $input['final_url'], 'observed_at' => $observed, 'encoding' => $input['encoding']], $input['requested_url'], $input['final_url'], ['adapter' => $input['provenance']['adapter'], 'captured_at_input' => $time]);
    }

    private static function check(bool $condition): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['capture' => 'Invalid bounded server capture.']);
        }
    }
}
