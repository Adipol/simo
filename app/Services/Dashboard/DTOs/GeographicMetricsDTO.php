<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class GeographicMetricsDTO
{
    public function __construct(
        public array $byCountry,
        public bool $hasData,
    ) {}

    public static function empty(): self
    {
        return new self(
            byCountry: [],
            hasData: false,
        );
    }
}
