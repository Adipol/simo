<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1.3 RED — gaceta_eventos_pep table structure verification.
 *
 * Verifies: table exists, all columns present, FK cascade on delete,
 * and that pgsql GiST trigram index is created.
 */
class GacetaEventosPepTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_gaceta_eventos_pep_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('gaceta_eventos_pep'),
            'Table gaceta_eventos_pep must exist after migrations'
        );
    }

    public function test_gaceta_eventos_pep_has_expected_columns(): void
    {
        $columns = [
            'id',
            'gaceta_norma_id',
            'pais',
            'persona_nombre',
            'persona_nombre_normalizado',
            'cargo',
            'cargo_categoria',
            'entidad',
            'tipo_evento',
            'interino',
            'estado_revision',
            'revisado_por',
            'revisado_at',
            'created_at',
            'updated_at',
        ];

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn('gaceta_eventos_pep', $column),
                "Column '{$column}' must exist in gaceta_eventos_pep"
            );
        }
    }

    public function test_gaceta_eventos_pep_interino_defaults_to_false(): void
    {
        $normaId = DB::table('gaceta_normas')->insertGetId([
            'pais'              => 'BO',
            'gaceta_id_externo' => 300001,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Test sumario for FK',
            'estado_extraccion' => 'pendiente',
        ]);

        DB::table('gaceta_eventos_pep')->insert([
            'gaceta_norma_id'            => $normaId,
            'pais'                       => 'BO',
            'persona_nombre'             => 'Juan Perez',
            'persona_nombre_normalizado' => 'JUAN PEREZ',
            'cargo'                      => 'Ministro de Educación',
            'tipo_evento'                => 'designacion',
            'estado_revision'            => 'pendiente',
        ]);

        $row = DB::table('gaceta_eventos_pep')->first();

        $this->assertFalse((bool) $row->interino);
    }

    public function test_gaceta_eventos_pep_estado_revision_defaults_to_pendiente(): void
    {
        $normaId = DB::table('gaceta_normas')->insertGetId([
            'pais'              => 'BO',
            'gaceta_id_externo' => 300002,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Test sumario 2',
            'estado_extraccion' => 'pendiente',
        ]);

        DB::table('gaceta_eventos_pep')->insert([
            'gaceta_norma_id'            => $normaId,
            'pais'                       => 'BO',
            'persona_nombre'             => 'Maria Lopez',
            'persona_nombre_normalizado' => 'MARIA LOPEZ',
            'cargo'                      => 'Ministra de Salud',
            'tipo_evento'                => 'designacion',
        ]);

        $row = DB::table('gaceta_eventos_pep')->first();

        $this->assertSame('pendiente', $row->estado_revision);
    }

    public function test_gaceta_eventos_pep_cascade_deletes_with_norma(): void
    {
        $normaId = DB::table('gaceta_normas')->insertGetId([
            'pais'              => 'BO',
            'gaceta_id_externo' => 300003,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Cascade test',
            'estado_extraccion' => 'pendiente',
        ]);

        DB::table('gaceta_eventos_pep')->insert([
            'gaceta_norma_id'            => $normaId,
            'pais'                       => 'BO',
            'persona_nombre'             => 'Carlos Ruiz',
            'persona_nombre_normalizado' => 'CARLOS RUIZ',
            'cargo'                      => 'Secretario General',
            'tipo_evento'                => 'designacion',
            'estado_revision'            => 'pendiente',
        ]);

        $this->assertSame(1, (int) DB::table('gaceta_eventos_pep')->count());

        DB::table('gaceta_normas')->where('id', $normaId)->delete();

        $this->assertSame(
            0,
            (int) DB::table('gaceta_eventos_pep')->count(),
            'Child eventos must be cascade-deleted when parent norma is deleted'
        );
    }

    public function test_gaceta_eventos_pep_trgm_index_exists_on_pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('GiST trigram index only exists on PostgreSQL');
        }

        $index = DB::selectOne(
            "SELECT 1 AS found FROM pg_indexes
             WHERE tablename = 'gaceta_eventos_pep'
               AND indexname = 'idx_gaceta_eventos_persona_trgm'"
        );

        $this->assertNotNull(
            $index,
            'GiST trigram index idx_gaceta_eventos_persona_trgm must exist on gaceta_eventos_pep'
        );
    }
}
