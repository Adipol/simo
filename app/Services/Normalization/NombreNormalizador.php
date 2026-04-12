<?php

declare(strict_types=1);

namespace App\Services\Normalization;

use App\Services\Normalization\DTOs\NombreNormalizadoDTO;

final class NombreNormalizador implements NombreNormalizadorInterface
{
    private const TITLE_REGEX = '/^(?:dr\.?|dra\.?|lic\.?|licdo\.?|licda\.?|ing\.?|mg\.?|mtra\.?|mtro\.?|prof\.?|profa\.?|sr\.?|sra\.?|srta\.?|ab\.?|abg\.?|don|doña)\s+/iu';

    private const ACCENT_MAP = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        'ñ' => 'n', 'Ñ' => 'n',
        'ü' => 'u', 'Ü' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
    ];

    public function normalize(string $name): NombreNormalizadoDTO
    {
        $original = $name;

        // R1: trim leading/trailing whitespace
        $working = trim($name);

        // R2: collapse multiple consecutive whitespace to single space
        $working = (string) preg_replace('/\s+/u', ' ', $working);

        // R3 + R4: remove academic and courtesy titles anchored at start
        $working = (string) preg_replace(self::TITLE_REGEX, '', $working);

        // R7: remove trailing punctuation (before title case so period doesn't affect caps)
        $working = (string) preg_replace('/[.,:;]+$/u', '', $working);

        // R5: title case (after punctuation removal)
        $working = mb_convert_case($working, MB_CASE_TITLE, 'UTF-8');

        $normalized = $working;

        // R6: accent strip + lowercase for matchingKey only
        $matchingKey = strtr($normalized, self::ACCENT_MAP);
        $matchingKey = mb_strtolower($matchingKey, 'UTF-8');

        return new NombreNormalizadoDTO($original, $normalized, $matchingKey);
    }

    public function normalizeNullable(?string $name): ?NombreNormalizadoDTO
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return $this->normalize($name);
    }
}
