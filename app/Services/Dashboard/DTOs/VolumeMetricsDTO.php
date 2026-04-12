<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class VolumeMetricsDTO
{
    public function __construct(
        public int $totalPeps,
        public int $totalOpis,
        public int $analyzedCount,
        public int $unreadCount,
        public array $monthlyTrend,
        public bool $hasData,
    ) {}

    public static function empty(): self
    {
        return new self(
            totalPeps: 0,
            totalOpis: 0,
            analyzedCount: 0,
            unreadCount: 0,
            monthlyTrend: [],
            hasData: false,
        );
    }
}
