<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

/**
 * Per-keyword discard analysis row.
 *
 * REQ-2 / SCN-2.1–2.3 — feedback-loop-from-descartados
 *
 * Only keywords with N ≥ MIN_SAMPLE_KEYWORD (5) appear in results.
 * pctDescartado = descartados / total * 100, rounded to 1 decimal.
 */
final readonly class KeywordAnalisisDTO
{
    public function __construct(
        public string $keyword,
        public int $total,
        public int $descartados,
        public int $relevantes,
        public float $pctDescartado,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            keyword: (string) ($row['keyword'] ?? ''),
            total: (int) ($row['total'] ?? 0),
            descartados: (int) ($row['descartados'] ?? 0),
            relevantes: (int) ($row['relevantes'] ?? 0),
            pctDescartado: (float) ($row['pct_descartado'] ?? 0.0),
        );
    }
}
