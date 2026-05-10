<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class BacklogAgeDTO
{
    public function __construct(
        public int $pendientes_antiguos,
        public int $dias_threshold,
        public ?int $mas_antiguo_dias,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            pendientes_antiguos: (int) ($data['pendientes_antiguos'] ?? 0),
            dias_threshold: (int) ($data['dias_threshold'] ?? 3),
            mas_antiguo_dias: isset($data['mas_antiguo_dias']) ? (int) $data['mas_antiguo_dias'] : null,
        );
    }
}
