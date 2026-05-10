<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class CambioSummary
{
    public function __construct(
        public int $id,
        public string $fuente_nombre,
        public string $riesgo,
        public int $lineas_nuevas,
        public int $lineas_quitadas,
        public ?string $analisis_snippet,
        public \DateTimeImmutable $fecha,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            fuente_nombre: (string) $data['fuente_nombre'],
            riesgo: (string) $data['riesgo'],
            lineas_nuevas: (int) ($data['lineas_nuevas'] ?? 0),
            lineas_quitadas: (int) ($data['lineas_quitadas'] ?? 0),
            analisis_snippet: isset($data['analisis_snippet']) ? (string) $data['analisis_snippet'] : null,
            fecha: new \DateTimeImmutable((string) $data['fecha']),
        );
    }
}
