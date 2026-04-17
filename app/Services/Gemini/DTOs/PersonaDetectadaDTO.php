<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

final readonly class PersonaDetectadaDTO
{
    public function __construct(
        public string $nombre,
        public ?string $cargo,
        public ?string $categoria,
        public ?string $entidadTipo,
        public int $confianza,
        public ?string $evento,
        public string $motivo,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            nombre: (string) ($data['nombre'] ?? ''),
            cargo: $data['cargo'] ?? null,
            categoria: $data['categoria'] ?? null,
            entidadTipo: $data['entidad_tipo'] ?? null,
            confianza: (int) ($data['confianza'] ?? 0),
            evento: $data['evento'] ?? null,
            motivo: (string) ($data['motivo'] ?? ''),
        );
    }
}
