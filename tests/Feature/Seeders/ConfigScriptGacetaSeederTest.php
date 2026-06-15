<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\ConfigScript;
use Database\Seeders\ConfigScriptGacetaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2.5 — ConfigScriptGacetaSeeder creates gaceta config_scripts row.
 */
class ConfigScriptGacetaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_gaceta_config_script_row(): void
    {
        $this->seed(ConfigScriptGacetaSeeder::class);

        $row = ConfigScript::where('script', 'gaceta')->first();

        $this->assertNotNull($row, 'A config_scripts row for script=gaceta must exist');
        $this->assertSame('gaceta', $row->script);
    }

    public function test_seeder_sets_expected_config_values(): void
    {
        $this->seed(ConfigScriptGacetaSeeder::class);

        $row = ConfigScript::where('script', 'gaceta')->first();

        $this->assertTrue($row->habilitado);
        $this->assertSame(60, $row->intervalo_minutos);
        $this->assertSame(30, $row->timeout_minutos);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ConfigScriptGacetaSeeder::class);
        $this->seed(ConfigScriptGacetaSeeder::class);

        $count = ConfigScript::where('script', 'gaceta')->count();

        $this->assertSame(1, $count, 'Running seeder twice must not create duplicate rows');
    }
}
