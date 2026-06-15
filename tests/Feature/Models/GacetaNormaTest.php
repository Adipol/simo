<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\GacetaEventoPep;
use App\Models\GacetaNorma;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2.1 RED — GacetaNorma model: relationships, casts, scopes.
 */
class GacetaNormaTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Relationships
    // =========================================================================

    public function test_gaceta_norma_has_many_eventos_pep(): void
    {
        $norma = GacetaNorma::create([
            'pais'              => 'BO',
            'gaceta_id_externo' => 400001,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Designa al ciudadano Juan Perez',
            'estado_extraccion' => 'pendiente',
        ]);

        GacetaEventoPep::create([
            'gaceta_norma_id'            => $norma->id,
            'pais'                       => 'BO',
            'persona_nombre'             => 'Juan Perez',
            'persona_nombre_normalizado' => 'JUAN PEREZ',
            'cargo'                      => 'Ministro de Educación',
            'tipo_evento'                => 'designacion',
            'estado_revision'            => 'pendiente',
        ]);

        GacetaEventoPep::create([
            'gaceta_norma_id'            => $norma->id,
            'pais'                       => 'BO',
            'persona_nombre'             => 'Maria Lopez',
            'persona_nombre_normalizado' => 'MARIA LOPEZ',
            'cargo'                      => 'Ministra de Salud',
            'tipo_evento'                => 'designacion',
            'estado_revision'            => 'pendiente',
        ]);

        $this->assertCount(2, $norma->eventosPep);
    }

    // =========================================================================
    // Casts
    // =========================================================================

    public function test_gaceta_norma_casts_raw_json_as_array(): void
    {
        $payload = ['norma_id' => 12345, 'tipo' => 'DP', 'extra' => ['key' => 'value']];

        $norma = GacetaNorma::create([
            'pais'              => 'BO',
            'gaceta_id_externo' => 400002,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Norma con raw_json',
            'raw_json'          => $payload,
            'estado_extraccion' => 'pendiente',
        ]);

        $fresh = GacetaNorma::find($norma->id);

        $this->assertIsArray($fresh->raw_json);
        $this->assertSame(12345, $fresh->raw_json['norma_id']);
    }

    public function test_gaceta_norma_casts_fecha_publicacion_as_date(): void
    {
        $norma = GacetaNorma::create([
            'pais'               => 'BO',
            'gaceta_id_externo'  => 400003,
            'tipo_norma'         => 'Decreto Presidencial',
            'sumario'            => 'Norma con fecha',
            'fecha_publicacion'  => '2026-06-01',
            'estado_extraccion'  => 'pendiente',
        ]);

        $fresh = GacetaNorma::find($norma->id);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->fecha_publicacion);
        $this->assertSame('2026-06-01', $fresh->fecha_publicacion->toDateString());
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function test_scope_pendiente_extraccion_returns_only_pendientes(): void
    {
        GacetaNorma::create([
            'pais' => 'BO', 'gaceta_id_externo' => 500001,
            'tipo_norma' => 'Decreto Presidencial', 'sumario' => 'Pendiente',
            'estado_extraccion' => 'pendiente',
        ]);
        GacetaNorma::create([
            'pais' => 'BO', 'gaceta_id_externo' => 500002,
            'tipo_norma' => 'Decreto Presidencial', 'sumario' => 'Completado',
            'estado_extraccion' => 'completado',
        ]);
        GacetaNorma::create([
            'pais' => 'BO', 'gaceta_id_externo' => 500003,
            'tipo_norma' => 'Decreto Presidencial', 'sumario' => 'Requiere detalle',
            'estado_extraccion' => 'requiere_detalle',
        ]);

        $pendientes = GacetaNorma::pendienteExtraccion()->get();

        $this->assertCount(1, $pendientes);
        $this->assertSame('pendiente', $pendientes->first()->estado_extraccion);
    }

    public function test_scope_requiere_revision_returns_only_requiere_detalle(): void
    {
        GacetaNorma::create([
            'pais' => 'BO', 'gaceta_id_externo' => 600001,
            'tipo_norma' => 'Decreto Presidencial', 'sumario' => 'Completado',
            'estado_extraccion' => 'completado',
        ]);
        GacetaNorma::create([
            'pais' => 'BO', 'gaceta_id_externo' => 600002,
            'tipo_norma' => 'Decreto Presidencial', 'sumario' => 'Requiere detalle',
            'estado_extraccion' => 'requiere_detalle',
        ]);

        $requieren = GacetaNorma::requiereRevision()->get();

        $this->assertCount(1, $requieren);
        $this->assertSame('requiere_detalle', $requieren->first()->estado_extraccion);
    }

    // =========================================================================
    // Unique constraint
    // =========================================================================

    public function test_duplicate_pais_gaceta_id_externo_throws_query_exception(): void
    {
        GacetaNorma::create([
            'pais'              => 'BO',
            'gaceta_id_externo' => 700001,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Original',
            'estado_extraccion' => 'pendiente',
        ]);

        $this->expectException(QueryException::class);

        GacetaNorma::create([
            'pais'              => 'BO',
            'gaceta_id_externo' => 700001,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Duplicate — should fail',
            'estado_extraccion' => 'pendiente',
        ]);
    }
}
