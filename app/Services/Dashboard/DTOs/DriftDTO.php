<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

/**
 * Temporal drift per keyword between current (0–30d) and previous (30–60d) windows.
 *
 * REQ-4 / SCN-4.1–4.3 — feedback-loop-from-descartados
 *
 * pctAnterior and driftPpt are null when the previous window has no data
 * for the keyword (rendered as "N/D" in output). This prevents division-by-zero
 * and false drift alerts on new keywords.
 */
final readonly class DriftDTO
{
    public function __construct(
        public string $keyword,
        public ?float $pctActual,
        public ?float $pctAnterior,
        public ?float $driftPpt,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            keyword: (string) ($row['keyword'] ?? ''),
            pctActual: isset($row['pct_actual']) ? (float) $row['pct_actual'] : null,
            pctAnterior: isset($row['pct_anterior']) ? (float) $row['pct_anterior'] : null,
            driftPpt: isset($row['drift_ppt']) ? (float) $row['drift_ppt'] : null,
        );
    }
}
