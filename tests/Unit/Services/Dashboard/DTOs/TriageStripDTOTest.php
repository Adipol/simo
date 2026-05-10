<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\TriageStripDTO;
use Tests\TestCase;

class TriageStripDTOTest extends TestCase
{
    private function validSparkline(): array
    {
        return [1, 2, 3, 4, 5, 6, 7];
    }

    // ─── Happy path ─────────────────────────────────────────────────────────

    public function test_constructor_stores_all_fields(): void
    {
        $dto = new TriageStripDTO(
            pendientes_alto: 3,
            pendientes_medio: 5,
            pendientes_bajo: 2,
            sin_leer: 8,
            sparkline_alto: [1, 2, 3, 4, 5, 6, 7],
            sparkline_medio: [0, 0, 1, 2, 3, 4, 5],
            sparkline_bajo: [0, 0, 0, 0, 0, 1, 2],
            sparkline_sin_leer: [5, 4, 3, 2, 1, 0, 0],
        );

        $this->assertSame(3, $dto->pendientes_alto);
        $this->assertSame(5, $dto->pendientes_medio);
        $this->assertSame(2, $dto->pendientes_bajo);
        $this->assertSame(8, $dto->sin_leer);
        $this->assertCount(7, $dto->sparkline_alto);
        $this->assertCount(7, $dto->sparkline_medio);
        $this->assertCount(7, $dto->sparkline_bajo);
        $this->assertCount(7, $dto->sparkline_sin_leer);
    }

    public function test_all_zeros_is_valid(): void
    {
        $zeros = [0, 0, 0, 0, 0, 0, 0];

        $dto = new TriageStripDTO(
            pendientes_alto: 0,
            pendientes_medio: 0,
            pendientes_bajo: 0,
            sin_leer: 0,
            sparkline_alto: $zeros,
            sparkline_medio: $zeros,
            sparkline_bajo: $zeros,
            sparkline_sin_leer: $zeros,
        );

        $this->assertSame(0, $dto->pendientes_alto);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $dto->sparkline_alto);
    }

    // ─── Sparkline validation ────────────────────────────────────────────────

    public function test_sparkline_alto_must_have_exactly_7_elements(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TriageStripDTO(
            pendientes_alto: 0,
            pendientes_medio: 0,
            pendientes_bajo: 0,
            sin_leer: 0,
            sparkline_alto: [1, 2, 3],           // Only 3 elements — invalid
            sparkline_medio: $this->validSparkline(),
            sparkline_bajo: $this->validSparkline(),
            sparkline_sin_leer: $this->validSparkline(),
        );
    }

    public function test_sparkline_medio_must_have_exactly_7_elements(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TriageStripDTO(
            pendientes_alto: 0,
            pendientes_medio: 0,
            pendientes_bajo: 0,
            sin_leer: 0,
            sparkline_alto: $this->validSparkline(),
            sparkline_medio: [1, 2, 3, 4, 5],    // 5 elements — invalid
            sparkline_bajo: $this->validSparkline(),
            sparkline_sin_leer: $this->validSparkline(),
        );
    }

    public function test_sparkline_bajo_must_have_exactly_7_elements(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TriageStripDTO(
            pendientes_alto: 0,
            pendientes_medio: 0,
            pendientes_bajo: 0,
            sin_leer: 0,
            sparkline_alto: $this->validSparkline(),
            sparkline_medio: $this->validSparkline(),
            sparkline_bajo: [],                   // 0 elements — invalid
            sparkline_sin_leer: $this->validSparkline(),
        );
    }

    public function test_sparkline_sin_leer_must_have_exactly_7_elements(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TriageStripDTO(
            pendientes_alto: 0,
            pendientes_medio: 0,
            pendientes_bajo: 0,
            sin_leer: 0,
            sparkline_alto: $this->validSparkline(),
            sparkline_medio: $this->validSparkline(),
            sparkline_bajo: $this->validSparkline(),
            sparkline_sin_leer: [1, 2, 3, 4, 5, 6, 7, 8],  // 8 elements — invalid
        );
    }
}
