<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\SourceHealthSummaryDTO;
use Tests\TestCase;

/**
 * RED → GREEN for SourceHealthSummaryDTO.
 * Tests: T3.3 (Phase 3 — DTOs)
 */
class SourceHealthSummaryDTOTest extends TestCase
{
    // ─── fromArray happy path ─────────────────────────────────────────────────

    public function test_from_array_creates_dto_with_all_fields(): void
    {
        $aggregationAt = new \DateTimeImmutable('2026-05-10 15:30:00');

        $dto = SourceHealthSummaryDTO::fromArray([
            'total_fuentes_activas' => 24,
            'ok' => 20,
            'degradadas' => 2,
            'muertas' => 1,
            'sin_info' => 1,
            'available' => true,
            'last_aggregation_at' => $aggregationAt,
        ]);

        $this->assertSame(24, $dto->total_fuentes_activas);
        $this->assertSame(20, $dto->ok);
        $this->assertSame(2, $dto->degradadas);
        $this->assertSame(1, $dto->muertas);
        $this->assertSame(1, $dto->sin_info);
        $this->assertTrue($dto->available);
        $this->assertSame($aggregationAt, $dto->last_aggregation_at);
    }

    public function test_from_array_available_false_when_no_active_fuentes(): void
    {
        $dto = SourceHealthSummaryDTO::fromArray([
            'total_fuentes_activas' => 0,
            'ok' => 0,
            'degradadas' => 0,
            'muertas' => 0,
            'sin_info' => 0,
            'available' => false,
            'last_aggregation_at' => new \DateTimeImmutable,
        ]);

        $this->assertFalse($dto->available);
        $this->assertSame(0, $dto->total_fuentes_activas);
    }

    // ─── fromArray throws on missing required fields ───────────────────────────

    public function test_from_array_throws_on_missing_total_fuentes_activas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/total_fuentes_activas/');

        SourceHealthSummaryDTO::fromArray([
            'ok' => 0,
            'degradadas' => 0,
            'muertas' => 0,
            'sin_info' => 0,
            'available' => false,
            'last_aggregation_at' => new \DateTimeImmutable,
        ]);
    }

    public function test_from_array_throws_on_missing_last_aggregation_at(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/last_aggregation_at/');

        SourceHealthSummaryDTO::fromArray([
            'total_fuentes_activas' => 10,
            'ok' => 10,
            'degradadas' => 0,
            'muertas' => 0,
            'sin_info' => 0,
            'available' => true,
        ]);
    }

    // ─── DTO invariant: ok + degradadas + muertas + sin_info === total ─────────

    public function test_count_invariant_holds_for_valid_data(): void
    {
        $dto = SourceHealthSummaryDTO::fromArray([
            'total_fuentes_activas' => 10,
            'ok' => 5,
            'degradadas' => 2,
            'muertas' => 1,
            'sin_info' => 2,
            'available' => true,
            'last_aggregation_at' => new \DateTimeImmutable,
        ]);

        $this->assertSame(
            $dto->total_fuentes_activas,
            $dto->ok + $dto->degradadas + $dto->muertas + $dto->sin_info,
            'Count invariant: ok + degradadas + muertas + sin_info must equal total_fuentes_activas'
        );
    }

    public function test_count_invariant_violated_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/invariant/i');

        // ok=5 + degradadas=2 + muertas=1 + sin_info=2 = 10, but total=11 → violation
        SourceHealthSummaryDTO::fromArray([
            'total_fuentes_activas' => 11,
            'ok' => 5,
            'degradadas' => 2,
            'muertas' => 1,
            'sin_info' => 2,
            'available' => true,
            'last_aggregation_at' => new \DateTimeImmutable,
        ]);
    }

    // ─── available derived from total ─────────────────────────────────────────

    public function test_all_sin_info_dto_is_available_true_because_has_active_fuentes(): void
    {
        $dto = SourceHealthSummaryDTO::fromArray([
            'total_fuentes_activas' => 24,
            'ok' => 0,
            'degradadas' => 0,
            'muertas' => 0,
            'sin_info' => 24,
            'available' => true,
            'last_aggregation_at' => new \DateTimeImmutable,
        ]);

        $this->assertTrue($dto->available);
        $this->assertSame(24, $dto->sin_info);
        $this->assertSame(0, $dto->ok);
    }
}
