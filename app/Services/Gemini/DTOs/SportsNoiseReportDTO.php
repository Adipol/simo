<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

final readonly class SportsNoiseReportDTO
{
    /** @param array<int, array<string, mixed>> $candidates */
    public function __construct(
        public array $candidates,
        public int $malformedMatchingLines,
        public bool $retentionGap,
        public array $humanGate,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidates' => $this->candidates,
            'malformed_matching_lines' => $this->malformedMatchingLines,
            'retention_gap' => $this->retentionGap,
            'human_gate' => $this->humanGate,
        ];
    }
}
