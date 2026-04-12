<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class RecentActivityDTO
{
    public function __construct(
        public array $highConfidencePeps,
        public array $latestCorrections,
    ) {}

    public static function empty(): self
    {
        return new self(
            highConfidencePeps: [],
            latestCorrections: [],
        );
    }
}
