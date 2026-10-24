<?php

declare(strict_types=1);

namespace App\Services\PepDirectory\DTOs;

use App\Exceptions\PepDirectory\CaptureException;
use stdClass;

/** INTERNAL child output, not an artifact-import API or proof of origin. */
final readonly class CapturedPageData
{
    public const MAX_OUTPUT = 2_097_152;

    private function __construct(public ServerCaptureData $capture, public string $peerIp, public bool $tlsVerified) {}

    public static function fromOutput(string $output, string $expectedUrl): self
    {
        try {
            self::check(strlen($output) <= self::MAX_OUTPUT);
            $json = str_ends_with($output, "\n") ? substr($output, 0, -1) : $output;
            $a = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
            // The producer emits compact ASCII JSON. Round-trip rejects duplicate
            // keys (including escaped aliases), extra frames and noncanonical values.
            self::check(json_encode($a, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) === $json);
            self::keys($a, ['format_version', 'requested_url', 'final_url', 'status', 'encoding', 'captured_at', 'body_base64', 'content_sha256', 'transport']);
            self::check($a->format_version === 1 && $a->requested_url === $expectedUrl && $a->final_url === $expectedUrl);
            self::check(is_int($a->status) && ! ($a->status >= 300 && $a->status < 400));
            self::check(is_string($a->body_base64));
            $body = base64_decode($a->body_base64, true);
            self::check(is_string($body) && strlen($body) <= 1_048_576 && base64_encode($body) === $a->body_base64);
            self::check($a->content_sha256 === hash('sha256', $body));
            self::keys($a->transport, ['adapter', 'peer_ip', 'tls_verified']);
            $scheme = parse_url($expectedUrl, PHP_URL_SCHEME);
            self::check(in_array($scheme, ['http', 'https'], true));
            self::check($a->transport->adapter === 'public-'.$scheme.'-pinned-v1' && $a->transport->tls_verified === ($scheme === 'https'));
            // Address admission belongs to the pinned producer, not this data DTO.
            self::check(is_string($a->transport->peer_ip) && filter_var($a->transport->peer_ip, FILTER_VALIDATE_IP) !== false);
            $capture = ServerCaptureData::fromArray(['raw_body' => $body, 'status' => $a->status,
                'requested_url' => $expectedUrl, 'final_url' => $expectedUrl,
                'captured_at' => $a->captured_at, 'encoding' => $a->encoding,
                'provenance' => ['adapter' => $a->transport->adapter]]);
            return new self($capture, $a->transport->peer_ip, $a->transport->tls_verified);
        } catch (\Throwable) {
            throw new CaptureException();
        }
    }

    /** JSON may contain any scalar/container type; validate before object access. */
    private static function keys(mixed $value, array $keys): void
    {
        self::check($value instanceof stdClass && count(get_object_vars($value)) === count($keys) && array_diff($keys, array_keys(get_object_vars($value))) === []);
    }

    private static function check(bool $condition): void
    {
        if (! $condition) {
            throw new CaptureException();
        }
    }
}
