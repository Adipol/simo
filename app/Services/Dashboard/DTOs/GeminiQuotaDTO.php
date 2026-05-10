<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class GeminiQuotaDTO
{
    public function __construct(
        public bool $available,
        public ?int $tokens_today,
        public ?int $requests_today,
        public ?int $daily_limit,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            available: (bool) ($data['available'] ?? false),
            tokens_today: isset($data['tokens_today']) ? (int) $data['tokens_today'] : null,
            requests_today: isset($data['requests_today']) ? (int) $data['requests_today'] : null,
            daily_limit: isset($data['daily_limit']) ? (int) $data['daily_limit'] : null,
        );
    }

    public static function unavailable(): self
    {
        return new self(
            available: false,
            tokens_today: null,
            requests_today: null,
            daily_limit: null,
        );
    }
}
