<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

/**
 * Aggregated precision metrics for discarded scraping results.
 *
 * REQ-1 / SCN-1.1–1.4 — feedback-loop-from-descartados
 *
 * precisionPct is null when the labeled sample is below MIN_SAMPLE_GLOBAL (10).
 * insufficientReason carries a human-readable explanation when precisionPct is null.
 */
final readonly class DescartadosMetricsDTO
{
    public function __construct(
        public int $totalProcesados,
        public int $totalDescartados,
        public int $totalRelevantes,
        public int $totalArchivados,
        public ?float $precisionPct,
        public string $insufficientReason = '',
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            totalProcesados: (int) ($row['total_procesados'] ?? 0),
            totalDescartados: (int) ($row['total_descartados'] ?? 0),
            totalRelevantes: (int) ($row['total_relevantes'] ?? 0),
            totalArchivados: (int) ($row['total_archivados'] ?? 0),
            precisionPct: isset($row['precision_pct']) ? (float) $row['precision_pct'] : null,
            insufficientReason: (string) ($row['insufficient_reason'] ?? ''),
        );
    }
}
