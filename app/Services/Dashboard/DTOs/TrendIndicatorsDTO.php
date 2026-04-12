<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class TrendIndicatorsDTO
{
    public function __construct(
        public array $pepsTrend,
        public array $opisTrend,
        public array $feedbackTrend,
    ) {}

    public static function empty(): self
    {
        $neutral = ['current' => 0, 'previous' => 0, 'delta_pct' => 0.0, 'direction' => 'neutral'];

        return new self(
            pepsTrend: $neutral,
            opisTrend: $neutral,
            feedbackTrend: $neutral,
        );
    }
}
