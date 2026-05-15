<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

/**
 * Per-sitio discard analysis row.
 *
 * REQ-3 / SCN-3.1–3.2 — feedback-loop-from-descartados
 *
 * sitioNombre is populated via JOIN with sitios_web.
 * Only sitios with N ≥ MIN_SAMPLE_KEYWORD (5) appear in results.
 * pctDescartado = descartados / total * 100, rounded to 1 decimal.
 */
final readonly class SitioAnalisisDTO
{
    public function __construct(
        public int $sitioId,
        public string $sitioNombre,
        public int $total,
        public int $descartados,
        public float $pctDescartado,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            sitioId: (int) ($row['sitio_id'] ?? 0),
            sitioNombre: (string) ($row['sitio_nombre'] ?? ''),
            total: (int) ($row['total'] ?? 0),
            descartados: (int) ($row['descartados'] ?? 0),
            pctDescartado: (float) ($row['pct_descartado'] ?? 0.0),
        );
    }
}
