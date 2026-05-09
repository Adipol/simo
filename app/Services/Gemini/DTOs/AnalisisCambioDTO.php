<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

use App\Exceptions\Gemini\GeminiInvalidResponseException;

final readonly class AnalisisCambioDTO
{
    private const RIESGO_VALUES = ['alto', 'medio', 'bajo'];

    /**
     * @param  array<int,array{nombre:string,cargo:?string}>  $personasDetectadas
     */
    public function __construct(
        public ?string $personaRemovida,
        public ?string $personaNueva,
        public ?string $cargo,
        public bool $esMae,
        public string $riesgo,
        public string $analisis,
        public array $personasDetectadas = [],
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
            personasDetectadas: [],
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

        // personas_detectadas: array de personas presentes en el diff o imagen,
        // independiente de si "entraron" o "salieron". Solo nombres LITERALMENTE
        // escritos. Default vacío si Gemini lo omite.
        $personasDetectadas = [];
        if (isset($data['personas_detectadas']) && is_array($data['personas_detectadas'])) {
            foreach ($data['personas_detectadas'] as $persona) {
                if (! is_array($persona) || empty($persona['nombre'])) {
                    continue;
                }
                $personasDetectadas[] = [
                    'nombre' => (string) $persona['nombre'],
                    'cargo' => isset($persona['cargo']) ? (string) $persona['cargo'] : null,
                ];
            }
        }

        return new self(
            personaRemovida: $data['persona_removida'] ?? null,
            personaNueva: $data['persona_nueva'] ?? null,
            cargo: $data['cargo'] ?? null,
            esMae: (bool) $data['es_mae'],
            riesgo: $riesgo,
            analisis: (string) $data['analisis'],
            personasDetectadas: $personasDetectadas,
        );
    }
}
