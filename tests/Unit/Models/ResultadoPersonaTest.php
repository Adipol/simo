<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ResultadoPersona;
use Tests\TestCase;

class ResultadoPersonaTest extends TestCase
{
    // =========================================================================
    // Cleanup 2 (bonus) — RED tests: getConfianzaColorClass() accessor
    // Extracted to satisfy GGA: hardcoded threshold 70 removed from Blade
    // =========================================================================

    /**
     * confianza >= 70 returns emerald class.
     */
    public function test_get_confianza_color_class_returns_emerald_when_at_or_above_70(): void
    {
        $p = new ResultadoPersona(['confianza' => 70]);
        $this->assertSame('text-emerald-600', $p->getConfianzaColorClass());
    }

    /**
     * confianza < 70 returns amber class.
     */
    public function test_get_confianza_color_class_returns_amber_when_below_70(): void
    {
        $p = new ResultadoPersona(['confianza' => 69]);
        $this->assertSame('text-amber-600', $p->getConfianzaColorClass());
    }

    /**
     * confianza = 0 returns amber class.
     */
    public function test_get_confianza_color_class_returns_amber_when_zero(): void
    {
        $p = new ResultadoPersona(['confianza' => 0]);
        $this->assertSame('text-amber-600', $p->getConfianzaColorClass());
    }

    /**
     * confianza = 100 (max) returns emerald class.
     */
    public function test_get_confianza_color_class_returns_emerald_when_100(): void
    {
        $p = new ResultadoPersona(['confianza' => 100]);
        $this->assertSame('text-emerald-600', $p->getConfianzaColorClass());
    }
}
