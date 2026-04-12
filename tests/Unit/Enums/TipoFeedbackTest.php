<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\TipoFeedback;
use PHPUnit\Framework\TestCase;

class TipoFeedbackTest extends TestCase
{
    public function test_has_two_cases(): void
    {
        $cases = TipoFeedback::cases();

        $this->assertCount(2, $cases);
    }

    public function test_correcto_case_has_correct_value(): void
    {
        $this->assertSame('correcto', TipoFeedback::Correcto->value);
    }

    public function test_incorrecto_case_has_correct_value(): void
    {
        $this->assertSame('incorrecto', TipoFeedback::Incorrecto->value);
    }

    public function test_is_backed_enum_with_string_type(): void
    {
        $reflection = new \ReflectionEnum(TipoFeedback::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', (string) $reflection->getBackingType());
    }

    public function test_can_create_from_string_value(): void
    {
        $this->assertSame(TipoFeedback::Correcto, TipoFeedback::from('correcto'));
        $this->assertSame(TipoFeedback::Incorrecto, TipoFeedback::from('incorrecto'));
    }
}
