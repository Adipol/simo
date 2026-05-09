<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

use App\Exceptions\Gemini\GeminiInvalidResponseException;

final readonly class AnalisisCambioDTO
{
    private const RIESGO_VALUES = ['alto', 'medio', 'bajo'];

    public function __construct(
        public ?string $personaRemovida,
        public ?string $personaNueva,
        public ?string $cargo,
        public bool $esMae,
        public string $riesgo,
        public string $analisis,
    ) {}

    /**
     * Build a "no novelty" DTO without invoking Gemini.
     *
     * Use this when the cambio has nothing real to analyze (empty diff,
     * no readable images) to prevent the model from hallucinating.
     */
    public static function sinNovedad(string $motivo): self
    {
        return new self(
            personaRemovida: null,
            personaNueva: null,
            cargo: null,
            esMae: false,
            riesgo: 'bajo',
            analisis: $motivo,
        );
    }

    public static function fromArray(array $data): self
    {
        $required = ['es_mae', 'riesgo', 'analisis'];

        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw new GeminiInvalidResponseException(
                    "Missing required field '{$field}' in Pro response."
                );
            }
        }

        $riesgo = (string) $data['riesgo'];

        if (! in_array($riesgo, self::RIESGO_VALUES, true)) {
            throw new GeminiInvalidResponseException(
                "Invalid 'riesgo' value '{$riesgo}'. Must be one of: alto, medio, bajo."
            );
        }

        return new self(
            personaRemovida: $data['persona_removida'] ?? null,
            personaNueva: $data['persona_nueva'] ?? null,
            cargo: $data['cargo'] ?? null,
            esMae: (bool) $data['es_mae'],
            riesgo: $riesgo,
            analisis: (string) $data['analisis'],
        );
    }
}
