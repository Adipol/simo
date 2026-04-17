<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

use App\Exceptions\Gemini\GeminiInvalidResponseException;

final readonly class FiltroResultadoDTO
{
    /**
     * @param  array<PersonaDetectadaDTO>  $personas
     */
    public function __construct(
        public array $personas,
        public string $motivoGeneral,
    ) {}

    public static function fromArray(array $data): self
    {
        if (! array_key_exists('personas', $data)) {
            throw new GeminiInvalidResponseException(
                "Missing required field 'personas' in Flash response."
            );
        }

        $personas = array_filter(
            array_map(
                fn (array $p) => PersonaDetectadaDTO::fromArray($p),
                $data['personas'],
            ),
            fn (PersonaDetectadaDTO $p) => $p->nombre !== '',
        );

        return new self(
            personas: array_values($personas),
            motivoGeneral: (string) ($data['motivo_general'] ?? ''),
        );
    }

    public function hasPersonas(): bool
    {
        return count($this->personas) > 0;
    }
}
