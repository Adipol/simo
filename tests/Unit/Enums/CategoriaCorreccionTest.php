<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CategoriaCorreccion;
use PHPUnit\Framework\TestCase;

class CategoriaCorreccionTest extends TestCase
{
    public function test_has_three_cases(): void
    {
        $cases = CategoriaCorreccion::cases();

        $this->assertCount(3, $cases);
    }

    public function test_pep_case_has_correct_value(): void
    {
        $this->assertSame('PEP', CategoriaCorreccion::PEP->value);
    }

    public function test_opi_case_has_correct_value(): void
    {
        $this->assertSame('OPI', CategoriaCorreccion::OPI->value);
    }

    public function test_no_rel_case_has_correct_value(): void
    {
        $this->assertSame('NO_REL', CategoriaCorreccion::NoRel->value);
    }

    public function test_is_backed_enum_with_string_type(): void
    {
        $reflection = new \ReflectionEnum(CategoriaCorreccion::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', (string) $reflection->getBackingType());
    }

    public function test_can_create_from_string_value(): void
    {
        $this->assertSame(CategoriaCorreccion::PEP, CategoriaCorreccion::from('PEP'));
        $this->assertSame(CategoriaCorreccion::OPI, CategoriaCorreccion::from('OPI'));
        $this->assertSame(CategoriaCorreccion::NoRel, CategoriaCorreccion::from('NO_REL'));
    }
}
