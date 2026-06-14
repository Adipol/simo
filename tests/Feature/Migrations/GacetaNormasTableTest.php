<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1.1 RED — gaceta_normas table structure verification.
 *
 * Verifies: table exists, all columns present, unique constraints enforced,
 * and standard indexes created.
 */
class GacetaNormasTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_gaceta_normas_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('gaceta_normas'),
            'Table gaceta_normas must exist after migrations'
        );
    }

    public function test_gaceta_normas_has_expected_columns(): void
    {
        $columns = [
            'id',
            'pais',
            'gaceta_id_externo',
            'numero_decreto',
            'tipo_norma',
            'edicion',
            'fecha_publicacion',
            'sumario',
            'texto_completo',
            'pdf_url',
            'pdf_archivado_path',
            'raw_json',
            'estado_extraccion',
            'created_at',
            'updated_at',
        ];

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn('gaceta_normas', $column),
                "Column '{$column}' must exist in gaceta_normas"
            );
        }
    }

    public function test_gaceta_normas_unique_pais_gaceta_id_externo_is_enforced(): void
    {
        DB::table('gaceta_normas')->insert([
            'pais'              => 'BO',
            'gaceta_id_externo' => 281243,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Test sumario A',
            'estado_extraccion' => 'pendiente',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('gaceta_normas')->insert([
            'pais'              => 'BO',
            'gaceta_id_externo' => 281243,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Duplicate — should fail',
            'estado_extraccion' => 'pendiente',
        ]);
    }

    public function test_gaceta_normas_unique_pais_numero_decreto_is_enforced(): void
    {
        DB::table('gaceta_normas')->insert([
            'pais'              => 'BO',
            'gaceta_id_externo' => 111111,
            'numero_decreto'    => '0001/2026',
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Decreto A',
            'estado_extraccion' => 'pendiente',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('gaceta_normas')->insert([
            'pais'              => 'BO',
            'gaceta_id_externo' => 222222,
            'numero_decreto'    => '0001/2026',
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Decreto B — same numero_decreto, should fail',
            'estado_extraccion' => 'pendiente',
        ]);
    }

    public function test_gaceta_normas_different_pais_same_gaceta_id_externo_is_allowed(): void
    {
        DB::table('gaceta_normas')->insert([
            'pais'              => 'BO',
            'gaceta_id_externo' => 999,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Bolivia decree',
            'estado_extraccion' => 'pendiente',
        ]);

        DB::table('gaceta_normas')->insert([
            'pais'              => 'HN',
            'gaceta_id_externo' => 999,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Honduras decree',
            'estado_extraccion' => 'pendiente',
        ]);

        $this->assertSame(2, (int) DB::table('gaceta_normas')->count());
    }

    public function test_gaceta_normas_different_pais_same_numero_decreto_is_allowed(): void
    {
        // Country-agnostic contract: the numero_decreto UNIQUE is per-pais, so two
        // countries can legitimately share the same decree number without colliding.
        DB::table('gaceta_normas')->insert([
            'pais'              => 'BO',
            'gaceta_id_externo' => 700001,
            'numero_decreto'    => '5631',
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Bolivia decreto 5631',
            'estado_extraccion' => 'pendiente',
        ]);

        DB::table('gaceta_normas')->insert([
            'pais'              => 'HN',
            'gaceta_id_externo' => 700002,
            'numero_decreto'    => '5631',
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Honduras decreto 5631 — same numero, different pais, allowed',
            'estado_extraccion' => 'pendiente',
        ]);

        $this->assertSame(2, (int) DB::table('gaceta_normas')->where('numero_decreto', '5631')->count());
    }

    public function test_gaceta_normas_estado_extraccion_defaults_to_pendiente(): void
    {
        DB::table('gaceta_normas')->insert([
            'pais'              => 'BO',
            'gaceta_id_externo' => 55555,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Without explicit estado',
        ]);

        $row = DB::table('gaceta_normas')->first();

        $this->assertSame('pendiente', $row->estado_extraccion);
    }
}
