<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\GacetaEventoPep;
use App\Models\GacetaNorma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2.3 RED — GacetaEventoPep model: relationship, casts, scopes.
 */
class GacetaEventoPepTest extends TestCase
{
    use RefreshDatabase;

    private function makeNorma(int $idExterno = 800001): GacetaNorma
    {
        return GacetaNorma::create([
            'pais'              => 'BO',
            'gaceta_id_externo' => $idExterno,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Sumario de norma ' . $idExterno,
            'estado_extraccion' => 'pendiente',
        ]);
    }

    private function makeEvento(GacetaNorma $norma, array $overrides = []): GacetaEventoPep
    {
        return GacetaEventoPep::create(array_merge([
            'gaceta_norma_id'            => $norma->id,
            'pais'                       => 'BO',
            'persona_nombre'             => 'Ana Garcia',
            'persona_nombre_normalizado' => 'ANA GARCIA',
            'cargo'                      => 'Ministra de Justicia',
            'tipo_evento'                => 'designacion',
            'estado_revision'            => 'pendiente',
        ], $overrides));
    }

    // =========================================================================
    // Relationship
    // =========================================================================

    public function test_gaceta_evento_pep_belongs_to_gaceta_norma(): void
    {
        $norma  = $this->makeNorma(800001);
        $evento = $this->makeEvento($norma);

        $this->assertInstanceOf(GacetaNorma::class, $evento->gacetaNorma);
        $this->assertSame($norma->id, $evento->gacetaNorma->id);
    }

    // =========================================================================
    // Casts
    // =========================================================================

    public function test_gaceta_evento_pep_casts_interino_as_bool(): void
    {
        $norma  = $this->makeNorma(800002);
        $evento = $this->makeEvento($norma, ['interino' => true]);

        $fresh = GacetaEventoPep::find($evento->id);

        $this->assertTrue($fresh->interino);
    }

    public function test_gaceta_evento_pep_interino_defaults_to_false(): void
    {
        $norma  = $this->makeNorma(800003);
        $evento = $this->makeEvento($norma);

        $fresh = GacetaEventoPep::find($evento->id);

        $this->assertFalse($fresh->interino);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function test_scope_pendiente_revision_returns_only_pendientes(): void
    {
        $norma = $this->makeNorma(900001);

        $this->makeEvento($norma, ['estado_revision' => 'pendiente']);
        $this->makeEvento($norma, [
            'persona_nombre'             => 'Luis Vargas',
            'persona_nombre_normalizado' => 'LUIS VARGAS',
            'cargo'                      => 'Secretario',
            'estado_revision'            => 'aprobado',
        ]);

        $pendientes = GacetaEventoPep::pendienteRevision()->get();

        $this->assertCount(1, $pendientes);
        $this->assertSame('pendiente', $pendientes->first()->estado_revision);
    }

    public function test_scope_por_pais_filters_by_pais(): void
    {
        $normaBO = $this->makeNorma(900002);
        $normaHN = GacetaNorma::create([
            'pais'              => 'HN',
            'gaceta_id_externo' => 900003,
            'tipo_norma'        => 'Decreto Presidencial',
            'sumario'           => 'Honduras',
            'estado_extraccion' => 'pendiente',
        ]);

        $this->makeEvento($normaBO, ['pais' => 'BO']);
        GacetaEventoPep::create([
            'gaceta_norma_id'            => $normaHN->id,
            'pais'                       => 'HN',
            'persona_nombre'             => 'Roberto Paz',
            'persona_nombre_normalizado' => 'ROBERTO PAZ',
            'cargo'                      => 'Ministro',
            'tipo_evento'                => 'designacion',
            'estado_revision'            => 'pendiente',
        ]);

        $boEventos = GacetaEventoPep::porPais('BO')->get();

        $this->assertCount(1, $boEventos);
        $this->assertSame('BO', $boEventos->first()->pais);
    }
}
