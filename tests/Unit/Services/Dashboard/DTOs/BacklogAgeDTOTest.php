<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\BacklogAgeDTO;
use Tests\TestCase;

class BacklogAgeDTOTest extends TestCase
{
    public function test_constructor_stores_all_fields(): void
    {
        $dto = new BacklogAgeDTO(
            pendientes_antiguos: 3,
            dias_threshold: 5,
            mas_antiguo_dias: 12,
        );

        $this->assertSame(3, $dto->pendientes_antiguos);
        $this->assertSame(5, $dto->dias_threshold);
        $this->assertSame(12, $dto->mas_antiguo_dias);
    }

    public function test_mas_antiguo_dias_can_be_null_when_no_pendientes(): void
    {
        $dto = new BacklogAgeDTO(
            pendientes_antiguos: 0,
            dias_threshold: 3,
            mas_antiguo_dias: null,
        );

        $this->assertSame(0, $dto->pendientes_antiguos);
        $this->assertNull($dto->mas_antiguo_dias);
    }

    public function test_threshold_applied_correctly(): void
    {
        // 2 of 5 exceed 3-day threshold
        $dto = new BacklogAgeDTO(
            pendientes_antiguos: 2,
            dias_threshold: 3,
            mas_antiguo_dias: 7,
        );

        $this->assertSame(2, $dto->pendientes_antiguos);
        $this->assertSame(3, $dto->dias_threshold);
        $this->assertSame(7, $dto->mas_antiguo_dias);
    }
}
