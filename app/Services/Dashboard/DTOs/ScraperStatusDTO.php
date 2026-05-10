<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class ScraperStatusDTO
{
    public function __construct(
        public string $name,
        public string $state,
        public ?\DateTimeImmutable $last_run,
        public ?float $duration_seconds,
        public string $status,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            state: (string) ($data['state'] ?? 'idle'),
            last_run: isset($data['last_run']) ? new \DateTimeImmutable((string) $data['last_run']) : null,
            duration_seconds: isset($data['duration_seconds']) ? (float) $data['duration_seconds'] : null,
            status: (string) ($data['status'] ?? 'no_data'),
        );
    }

    public static function noData(string $name): self
    {
        return new self(
            name: $name,
            state: 'idle',
            last_run: null,
            duration_seconds: null,
            status: 'no_data',
        );
    }

    /**
     * Returns a human-readable elapsed time since the last run, e.g. "01:30 ago".
     * Returns null when last_run is not set.
     */
    public function ageFormatted(): ?string
    {
        if ($this->last_run === null) {
            return null;
        }

        return $this->last_run->diff(new \DateTimeImmutable)->format('%H:%I').' ago';
    }
}
