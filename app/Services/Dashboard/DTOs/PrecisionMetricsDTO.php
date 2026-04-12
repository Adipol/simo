<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class PrecisionMetricsDTO
{
    public function __construct(
        public float $overallAccuracy,
        public array $byBucket,
        public int $totalFeedbacks,
        public bool $hasData,
    ) {}

    public static function empty(): self
    {
        return new self(
            overallAccuracy: 0.0,
            byBucket: [],
            totalFeedbacks: 0,
            hasData: false,
        );
    }
}
