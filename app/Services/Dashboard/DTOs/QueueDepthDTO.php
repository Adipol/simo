<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class QueueDepthDTO
{
    /** Warning threshold: queues above this combined count are flagged amber. */
    private const WARNING_THRESHOLD = 50;

    public int $total_pending;
    public string $status;

    public function __construct(
        public int $gemini_pro_pending,
        public int $gemini_flash_pending,
        public int $other_pending,
    ) {
        $this->total_pending = $gemini_pro_pending + $gemini_flash_pending + $other_pending;
        $this->status        = $this->total_pending > self::WARNING_THRESHOLD ? 'warning' : 'ok';
    }

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
