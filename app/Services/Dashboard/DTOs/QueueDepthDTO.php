<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class QueueDepthDTO
{
    public function __construct(
        public int $gemini_pro_pending,
        public int $gemini_flash_pending,
        public int $other_pending,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            gemini_pro_pending: (int) ($data['gemini_pro_pending'] ?? 0),
            gemini_flash_pending: (int) ($data['gemini_flash_pending'] ?? 0),
            other_pending: (int) ($data['other_pending'] ?? 0),
        );
    }

    public static function empty(): self
    {
        return new self(
            gemini_pro_pending: 0,
            gemini_flash_pending: 0,
            other_pending: 0,
        );
    }
}
