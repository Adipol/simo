<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class RecentDiscoveriesDTO
{
    /**
     * @param  array<PepHighConfidence>  $top_peps
     * @param  array<CambioSummary>  $top_cambios
     */
    public function __construct(
        public array $top_peps,
        public array $top_cambios,
    ) {}

    /**
     * @param  array{top_peps: array<PepHighConfidence>, top_cambios: array<CambioSummary>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            top_peps: $data['top_peps'] ?? [],
            top_cambios: $data['top_cambios'] ?? [],
        );
    }
}
