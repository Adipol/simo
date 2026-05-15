<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

/**
 * Gemini confidence bucket with human discard rate.
 *
 * REQ-5 / SCN-5.1–5.3 — feedback-loop-from-descartados
 *
 * Frozen bucket boundaries: 0-49, 50-69, 70-84, 85-100.
 * pctDescartado = descartados / total * 100, rounded to 1 decimal.
 * Buckets with zero rows are omitted from results (per SCN-5.3).
 */
final readonly class ConfianzaBucketDTO
{
    public function __construct(
        public string $bucket,
        public int $total,
        public int $descartados,
        public float $pctDescartado,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            bucket: (string) ($row['bucket'] ?? ''),
            total: (int) ($row['total'] ?? 0),
            descartados: (int) ($row['descartados'] ?? 0),
            pctDescartado: (float) ($row['pct_descartado'] ?? 0.0),
        );
    }
}
