<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class TriageStripDTO
{
    /** @param array<int> $sparkline_alto */
    /** @param array<int> $sparkline_medio */
    /** @param array<int> $sparkline_bajo */
    /** @param array<int> $sparkline_sin_leer */
    public function __construct(
        public int $pendientes_alto,
        public int $pendientes_medio,
        public int $pendientes_bajo,
        public int $sin_leer,
        public array $sparkline_alto,
        public array $sparkline_medio,
        public array $sparkline_bajo,
        public array $sparkline_sin_leer,
    ) {
        $this->validateSparkline('sparkline_alto', $sparkline_alto);
        $this->validateSparkline('sparkline_medio', $sparkline_medio);
        $this->validateSparkline('sparkline_bajo', $sparkline_bajo);
        $this->validateSparkline('sparkline_sin_leer', $sparkline_sin_leer);
    }

    public static function fromArray(array $data): self
    {
        $zeros = [0, 0, 0, 0, 0, 0, 0];

        return new self(
            pendientes_alto: (int) ($data['pendientes_alto'] ?? 0),
            pendientes_medio: (int) ($data['pendientes_medio'] ?? 0),
            pendientes_bajo: (int) ($data['pendientes_bajo'] ?? 0),
            sin_leer: (int) ($data['sin_leer'] ?? 0),
            sparkline_alto: $data['sparkline_alto'] ?? $zeros,
            sparkline_medio: $data['sparkline_medio'] ?? $zeros,
            sparkline_bajo: $data['sparkline_bajo'] ?? $zeros,
            sparkline_sin_leer: $data['sparkline_sin_leer'] ?? $zeros,
        );
    }

    private function validateSparkline(string $name, array $data): void
    {
        if (count($data) !== 7) {
            throw new \InvalidArgumentException(
                "Sparkline '{$name}' must have exactly 7 elements, got ".count($data).'.'
            );
        }
    }
}
