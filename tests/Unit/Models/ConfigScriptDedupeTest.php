<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ConfigScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3.T10 — Tests for ConfigScript dedupe helpers.
 *
 * Verifies that the static dedupe() helper, dedupeThreshold(), and ventanaDias()
 * correctly read from the config_scripts row seeded by migration M4.
 *
 * Design D5: config_scripts reuses intervalo_minutos as ventana_dias and
 * notas JSON as the threshold payload.
 */
class ConfigScriptDedupeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * P3.T10 — dedupeThreshold() returns 0.90 from M4 seeded row.
     */
    public function test_dedupe_threshold_default_0_90(): void
    {
        $config = ConfigScript::dedupe();

        $this->assertSame(0.90, $config->dedupeThreshold(), 'dedupeThreshold() must return 0.90 from notas JSON');
    }

    /**
     * P3.T10 — ventanaDias() returns 7 from M4 seeded row (intervalo_minutos = 7).
     */
    public function test_ventana_dias_default_7(): void
    {
        $config = ConfigScript::dedupe();

        $this->assertSame(7, $config->ventanaDias(), 'ventanaDias() must return 7 from intervalo_minutos column');
    }

    /**
     * P3.T10 — habilitado is true by default after M4 seed.
     */
    public function test_habilitado_is_true_by_default(): void
    {
        $config = ConfigScript::dedupe();

        $this->assertTrue($config->habilitado, 'Dedupe config must be habilitado=true after M4 seed');
    }

    /**
     * Additional: dedupe() returns a ConfigScript instance.
     */
    public function test_dedupe_returns_config_script_instance(): void
    {
        $config = ConfigScript::dedupe();

        $this->assertInstanceOf(ConfigScript::class, $config, 'dedupe() must return a ConfigScript instance');
        $this->assertSame('dedupe', $config->script, 'dedupe() row must have script = "dedupe"');
    }
}
