<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class LatencyDTO
{
    public function __construct(
        public bool $available,
        public ?float $p50_seconds,
        public ?float $p95_seconds,
        public int $sample_size,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            available: (bool) ($data['available'] ?? false),
            p50_seconds: isset($data['p50_seconds']) ? (float) $data['p50_seconds'] : null,
            p95_seconds: isset($data['p95_seconds']) ? (float) $data['p95_seconds'] : null,
            sample_size: (int) ($data['sample_size'] ?? 0),
        );
    }

    public static function unavailable(): self
    {
        return new self(
            available: false,
            p50_seconds: null,
            p95_seconds: null,
            sample_size: 0,
        );
    }
}
