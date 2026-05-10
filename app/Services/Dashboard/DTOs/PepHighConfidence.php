<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class PepHighConfidence
{
    public function __construct(
        public int $id,
        public string $nombre,
        public ?string $cargo,
        public ?string $pais,
        public float $confianza,
        public string $categoria,
        public \DateTimeImmutable $fecha,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            nombre: (string) $data['nombre'],
            cargo: isset($data['cargo']) ? (string) $data['cargo'] : null,
            pais: isset($data['pais']) ? (string) $data['pais'] : null,
            confianza: (float) $data['confianza'],
            categoria: (string) $data['categoria'],
            fecha: new \DateTimeImmutable((string) $data['fecha']),
        );
    }
}
