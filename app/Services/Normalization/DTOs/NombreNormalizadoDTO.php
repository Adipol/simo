<?php

declare(strict_types=1);

namespace App\Services\Normalization\DTOs;

final readonly class NombreNormalizadoDTO
{
    public function __construct(
        public string $original,
        public string $normalized,
        public string $matchingKey,
    ) {}

    public static function empty(): self
    {
        return new self('', '', '');
    }

    public function equals(self $other): bool
    {
        return $this->matchingKey === $other->matchingKey;
    }
}
