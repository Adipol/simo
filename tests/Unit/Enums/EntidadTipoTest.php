<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\EntidadTipo;
use PHPUnit\Framework\TestCase;

class EntidadTipoTest extends TestCase
{
    public function test_has_three_cases(): void
    {
        $cases = EntidadTipo::cases();

        $this->assertCount(3, $cases);
    }

    public function test_todas_case_has_correct_value(): void
    {
        $this->assertSame('todas', EntidadTipo::Todas->value);
    }

    public function test_publica_case_has_correct_value(): void
    {
        $this->assertSame('publica', EntidadTipo::Publica->value);
    }

    public function test_ambas_case_has_correct_value(): void
    {
        $this->assertSame('ambas', EntidadTipo::Ambas->value);
    }

    public function test_is_backed_enum_with_string_type(): void
    {
        $reflection = new \ReflectionEnum(EntidadTipo::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', (string) $reflection->getBackingType());
    }

    public function test_can_create_from_string_value(): void
    {
        $this->assertSame(EntidadTipo::Todas, EntidadTipo::from('todas'));
        $this->assertSame(EntidadTipo::Publica, EntidadTipo::from('publica'));
        $this->assertSame(EntidadTipo::Ambas, EntidadTipo::from('ambas'));
    }
}
