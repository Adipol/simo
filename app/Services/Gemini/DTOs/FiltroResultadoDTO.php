<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

use App\Exceptions\Gemini\GeminiInvalidResponseException;

final readonly class FiltroResultadoDTO
{
    public function __construct(
        public bool $isPep,
        public ?string $nombre,
        public ?string $cargo,
        public ?string $categoria,
        public int $confianza,
        public string $motivo,
    ) {}

    public static function fromArray(array $data): self
    {
        $required = ['is_pep', 'confianza', 'motivo'];

        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw new GeminiInvalidResponseException(
                    "Missing required field '{$field}' in Flash response."
                );
            }
        }

        return new self(
            isPep: (bool) $data['is_pep'],
            nombre: $data['nombre'] ?? null,
            cargo: $data['cargo'] ?? null,
            categoria: $data['categoria'] ?? null,
            confianza: (int) $data['confianza'],
            motivo: (string) $data['motivo'],
        );
    }
}
