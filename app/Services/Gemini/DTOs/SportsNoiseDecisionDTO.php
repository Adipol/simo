<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

final readonly class SportsNoiseDecisionDTO
{
    /**
     * @param  array<int, string>  $reasonCodes
     * @param  array<int, string>  $escapeCodes
     */
    public function __construct(
        public string $outcome,
        public string $ruleVersion,
        public array $reasonCodes,
        public array $escapeCodes,
        public ?string $titleFingerprint,
        public ?string $excerpt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            outcome: (string) $data['outcome'],
            ruleVersion: (string) $data['rule_version'],
            reasonCodes: array_values($data['reason_codes'] ?? []),
            escapeCodes: array_values($data['escape_codes'] ?? []),
            titleFingerprint: isset($data['title_fingerprint']) ? (string) $data['title_fingerprint'] : null,
            excerpt: isset($data['excerpt']) ? (string) $data['excerpt'] : null,
        );
    }
}
