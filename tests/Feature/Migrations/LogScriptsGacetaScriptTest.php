<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 1.5 RED — log_scripts.script column accepts 'gaceta' after migration.
 *
 * The original CREATE TABLE restricted script to ('scraper','pep_monitor').
 * Migration add_gaceta_to_log_scripts_script must widen the constraint.
 */
class LogScriptsGacetaScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_scripts_accepts_gaceta_script_value(): void
    {
        DB::table('log_scripts')->insert([
            'script'  => 'gaceta',
            'estado'  => 'iniciado',
            'inicio'  => now(),
        ]);

        $row = DB::table('log_scripts')->where('script', 'gaceta')->first();

        $this->assertNotNull($row, 'A log_scripts row with script=gaceta must be insertable');
        $this->assertSame('gaceta', $row->script);
    }

    public function test_log_scripts_still_accepts_scraper_script_value(): void
    {
        DB::table('log_scripts')->insert([
            'script' => 'scraper',
            'estado' => 'iniciado',
            'inicio' => now(),
        ]);

        $row = DB::table('log_scripts')->where('script', 'scraper')->first();

        $this->assertSame('scraper', $row->script);
    }

    public function test_log_scripts_still_accepts_pep_monitor_script_value(): void
    {
        DB::table('log_scripts')->insert([
            'script' => 'pep_monitor',
            'estado' => 'iniciado',
            'inicio' => now(),
        ]);

        $row = DB::table('log_scripts')->where('script', 'pep_monitor')->first();

        $this->assertSame('pep_monitor', $row->script);
    }

    public function test_log_scripts_gaceta_row_completes_lifecycle(): void
    {
        $id = DB::table('log_scripts')->insertGetId([
            'script'           => 'gaceta',
            'estado'           => 'iniciado',
            'inicio'           => now(),
            'items_procesados' => 0,
        ]);

        DB::table('log_scripts')->where('id', $id)->update([
            'estado'             => 'completado',
            'fin'                => now(),
            'items_procesados'   => 10,
            'items_resultado'    => 3,
        ]);

        $row = DB::table('log_scripts')->where('id', $id)->first();

        $this->assertSame('completado', $row->estado);
        $this->assertSame(10, (int) $row->items_procesados);
        $this->assertSame(3, (int) $row->items_resultado);
    }
}
