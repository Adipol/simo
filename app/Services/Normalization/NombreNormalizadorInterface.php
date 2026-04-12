<?php

declare(strict_types=1);

namespace App\Services\Normalization;

use App\Services\Normalization\DTOs\NombreNormalizadoDTO;

interface NombreNormalizadorInterface
{
    public function normalize(string $name): NombreNormalizadoDTO;

    public function normalizeNullable(?string $name): ?NombreNormalizadoDTO;
}
