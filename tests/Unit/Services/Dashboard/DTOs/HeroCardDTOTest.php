<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\HeroCardDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeroCardDTOTest extends TestCase
{
    // ─── fromArray happy path ───────────────────────────────────────────────

    public function test_from_array_creates_dto_with_all_fields(): void
    {
        $data = [
            'id'            => 42,
            'fuente_nombre' => 'Gobierno Bolivia',
            'riesgo'        => 'alto',
            'es_mae'        => true,
            'dias_pendiente' => 5,
            'score'         => 10.66,
            'accion_url'    => '/pep/cambios?cambio=42',
            'fecha'         => '2026-05-01 12:00:00',
        ];

        $dto = HeroCardDTO::fromArray($data);

        $this->assertSame(42, $dto->id);
        $this->assertSame('Gobierno Bolivia', $dto->fuente_nombre);
        $this->assertSame('alto', $dto->riesgo);
        $this->assertTrue($dto->es_mae);
        $this->assertSame(5, $dto->dias_pendiente);
        $this->assertEqualsWithDelta(10.66, $dto->score, 0.001);
        $this->assertSame('/pep/cambios?cambio=42', $dto->accion_url);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dto->fecha);
    }

    public function test_from_array_riesgo_bajo_es_mae_false(): void
    {
        $data = [
            'id'            => 1,
            'fuente_nombre' => 'Fuente X',
            'riesgo'        => 'bajo',
            'es_mae'        => false,
            'dias_pendiente' => 0,
            'score'         => 0.0,
            'accion_url'    => '/pep/cambios?cambio=1',
            'fecha'         => '2026-01-15 08:30:00',
        ];

        $dto = HeroCardDTO::fromArray($data);

        $this->assertSame('bajo', $dto->riesgo);
        $this->assertFalse($dto->es_mae);
        $this->assertSame(0, $dto->dias_pendiente);
    }

    // ─── Missing required field throws ──────────────────────────────────────

    #[DataProvider('requiredFieldsProvider')]
    public function test_from_array_throws_on_missing_field(string $missingField): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = [
            'id'            => 1,
            'fuente_nombre' => 'X',
            'riesgo'        => 'medio',
            'es_mae'        => false,
            'dias_pendiente' => 0,
            'score'         => 0.0,
            'accion_url'    => '/pep/cambios?cambio=1',
            'fecha'         => '2026-01-01 00:00:00',
        ];

        unset($data[$missingField]);

        HeroCardDTO::fromArray($data);
    }

    public static function requiredFieldsProvider(): array
    {
        return [
            ['id'],
            ['fuente_nombre'],
            ['riesgo'],
            ['es_mae'],
            ['dias_pendiente'],
            ['score'],
            ['accion_url'],
            ['fecha'],
        ];
    }
}
