<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\SourceHealthDTO;
use Tests\TestCase;

/**
 * RED → GREEN for SourceHealthDTO.
 * Tests: T3.1 (Phase 3 — DTOs)
 */
class SourceHealthDTOTest extends TestCase
{
    // ─── fromArray happy path ─────────────────────────────────────────────────

    public function test_from_array_creates_dto_with_all_fields(): void
    {
        $lastRunAt = new \DateTimeImmutable('2026-05-10 15:30:00');
        $lastOkAt = new \DateTimeImmutable('2026-05-10 15:00:00');

        $dto = SourceHealthDTO::fromArray([
            'fuente_id' => 5,
            'nombre' => 'Ministerio de Economía',
            'status' => 'ok',
            'consecutive_failures' => 0,
            'last_run_at' => $lastRunAt,
            'last_ok_at' => $lastOkAt,
        ]);

        $this->assertSame(5, $dto->fuente_id);
        $this->assertSame('Ministerio de Economía', $dto->nombre);
        $this->assertSame('ok', $dto->status);
        $this->assertSame(0, $dto->consecutive_failures);
        $this->assertSame($lastRunAt, $dto->last_run_at);
        $this->assertSame($lastOkAt, $dto->last_ok_at);
    }

    public function test_from_array_accepts_null_optional_fields(): void
    {
        $dto = SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'nombre' => 'Fuente Test',
            'status' => 'sin_info',
            'consecutive_failures' => 0,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);

        $this->assertNull($dto->last_run_at);
        $this->assertNull($dto->last_ok_at);
        $this->assertSame('sin_info', $dto->status);
    }

    // ─── fromArray throws on missing required fields ───────────────────────────

    public function test_from_array_throws_on_missing_fuente_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fuente_id/');

        SourceHealthDTO::fromArray([
            'nombre' => 'Fuente Test',
            'status' => 'ok',
            'consecutive_failures' => 0,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);
    }

    public function test_from_array_throws_on_missing_nombre(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nombre/');

        SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'status' => 'ok',
            'consecutive_failures' => 0,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);
    }

    public function test_from_array_throws_on_missing_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/status/');

        SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'nombre' => 'Fuente Test',
            'consecutive_failures' => 0,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);
    }

    // ─── enum estado validation ────────────────────────────────────────────────

    public function test_valid_estado_ok_is_accepted(): void
    {
        $dto = SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'nombre' => 'Test',
            'status' => 'ok',
            'consecutive_failures' => 0,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);

        $this->assertSame('ok', $dto->status);
    }

    public function test_valid_estado_degradado_is_accepted(): void
    {
        $dto = SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'nombre' => 'Test',
            'status' => 'degradado',
            'consecutive_failures' => 3,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);

        $this->assertSame('degradado', $dto->status);
    }

    public function test_valid_estado_muerto_is_accepted(): void
    {
        $dto = SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'nombre' => 'Test',
            'status' => 'muerto',
            'consecutive_failures' => 10,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);

        $this->assertSame('muerto', $dto->status);
    }

    public function test_valid_estado_sin_info_is_accepted(): void
    {
        $dto = SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'nombre' => 'Test',
            'status' => 'sin_info',
            'consecutive_failures' => 0,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);

        $this->assertSame('sin_info', $dto->status);
    }

    public function test_invalid_estado_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/status/');

        SourceHealthDTO::fromArray([
            'fuente_id' => 1,
            'nombre' => 'Test',
            'status' => 'unknown_state',
            'consecutive_failures' => 0,
            'last_run_at' => null,
            'last_ok_at' => null,
        ]);
    }
}
