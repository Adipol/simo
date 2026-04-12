<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Normalization;

use App\Services\Normalization\DTOs\NombreNormalizadoDTO;
use Tests\TestCase;

class NombreNormalizadoDTOTest extends TestCase
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function test_constructor_stores_original_correctly(): void
    {
        $dto = new NombreNormalizadoDTO('Dr. Juan Pérez', 'Juan Pérez', 'juan perez');

        $this->assertSame('Dr. Juan Pérez', $dto->original);
    }

    public function test_constructor_stores_normalized_correctly(): void
    {
        $dto = new NombreNormalizadoDTO('Dr. Juan Pérez', 'Juan Pérez', 'juan perez');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_constructor_stores_matching_key_correctly(): void
    {
        $dto = new NombreNormalizadoDTO('Dr. Juan Pérez', 'Juan Pérez', 'juan perez');

        $this->assertSame('juan perez', $dto->matchingKey);
    }

    public function test_constructor_stores_all_three_properties(): void
    {
        $dto = new NombreNormalizadoDTO('orig', 'Norm', 'norm');

        $this->assertSame('orig', $dto->original);
        $this->assertSame('Norm', $dto->normalized);
        $this->assertSame('norm', $dto->matchingKey);
    }

    // ─── empty() factory ─────────────────────────────────────────────────────

    public function test_empty_returns_dto_with_three_empty_strings(): void
    {
        $dto = NombreNormalizadoDTO::empty();

        $this->assertSame('', $dto->original);
        $this->assertSame('', $dto->normalized);
        $this->assertSame('', $dto->matchingKey);
    }

    public function test_empty_returns_instance_of_dto(): void
    {
        $dto = NombreNormalizadoDTO::empty();

        $this->assertInstanceOf(NombreNormalizadoDTO::class, $dto);
    }

    // ─── equals() method ─────────────────────────────────────────────────────

    public function test_equals_returns_true_when_matching_keys_are_identical(): void
    {
        $a = new NombreNormalizadoDTO('Dr. Juan Pérez', 'Juan Pérez', 'juan perez');
        $b = new NombreNormalizadoDTO('JUAN PÉREZ', 'Juan Pérez', 'juan perez');

        $this->assertTrue($a->equals($b));
    }

    public function test_equals_returns_false_when_matching_keys_differ(): void
    {
        $a = new NombreNormalizadoDTO('Juan Pérez', 'Juan Pérez', 'juan perez');
        $b = new NombreNormalizadoDTO('María García', 'María García', 'maria garcia');

        $this->assertFalse($a->equals($b));
    }

    public function test_equals_is_symmetric(): void
    {
        $a = new NombreNormalizadoDTO('A', 'A', 'key');
        $b = new NombreNormalizadoDTO('B', 'B', 'key');

        $this->assertTrue($a->equals($b));
        $this->assertTrue($b->equals($a));
    }

    public function test_equals_compares_only_matching_key_not_original(): void
    {
        $a = new NombreNormalizadoDTO('DIFFERENT ORIGINAL', 'Juan Pérez', 'juan perez');
        $b = new NombreNormalizadoDTO('also different', 'Juan Pérez', 'juan perez');

        // Same matchingKey → equals even if original/normalized differ
        $this->assertTrue($a->equals($b));
    }

    // ─── Immutability ─────────────────────────────────────────────────────────

    public function test_dto_is_readonly(): void
    {
        $dto = new NombreNormalizadoDTO('orig', 'Norm', 'norm');

        // Attempt to set readonly property should throw Error
        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $dto->original = 'modified';
    }
}
