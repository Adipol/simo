<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class HeroCardDTO
{
    private const REQUIRED_FIELDS = [
        'id',
        'fuente_nombre',
        'riesgo',
        'es_mae',
        'dias_pendiente',
        'score',
        'accion_url',
        'fecha',
    ];

    public function __construct(
        public int $id,
        public string $fuente_nombre,
        public string $riesgo,
        public bool $es_mae,
        public int $dias_pendiente,
        public float $score,
        public string $accion_url,
        public \DateTimeImmutable $fecha,
    ) {}

    public static function fromArray(array $data): self
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                throw new \InvalidArgumentException(
                    "Missing required field '{$field}' in HeroCardDTO."
                );
            }
        }

        return new self(
            id: (int) $data['id'],
            fuente_nombre: (string) $data['fuente_nombre'],
            riesgo: (string) $data['riesgo'],
            es_mae: (bool) $data['es_mae'],
            dias_pendiente: (int) $data['dias_pendiente'],
            score: (float) $data['score'],
            accion_url: (string) $data['accion_url'],
            fecha: new \DateTimeImmutable((string) $data['fecha']),
        );
    }
}
