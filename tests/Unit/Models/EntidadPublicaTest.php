<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EntidadPublica;
use App\Models\Pais;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntidadPublicaTest extends TestCase
{
    use RefreshDatabase;

    private function createEntidad(array $overrides = []): EntidadPublica
    {
        return EntidadPublica::create(array_merge([
            'pais_codigo' => 'BO',
            'nombre' => 'YPFB',
            'sigla' => 'YPFB',
            'activo' => true,
        ], $overrides));
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function test_scope_active_returns_only_active_entidades(): void
    {
        $this->createEntidad(['activo' => true]);
        $this->createEntidad(['nombre' => 'Entidad Inactiva', 'sigla' => null, 'activo' => false]);

        $result = EntidadPublica::active()->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->activo);
    }

    public function test_scope_for_country_filters_by_pais_codigo(): void
    {
        $this->createEntidad(['pais_codigo' => 'BO']);
        $this->createEntidad(['nombre' => 'Empresa Honduras', 'pais_codigo' => 'HN']);

        $result = EntidadPublica::forCountry('BO')->get();

        $this->assertCount(1, $result);
        $this->assertSame('BO', $result->first()->pais_codigo);
    }

    // ─── Casts ───────────────────────────────────────────────────────────────

    public function test_activo_is_cast_to_boolean(): void
    {
        $entidad = $this->createEntidad(['activo' => true]);

        $this->assertIsBool($entidad->activo);
        $this->assertTrue($entidad->activo);
    }

    public function test_sigla_is_nullable(): void
    {
        $entidad = $this->createEntidad(['sigla' => null]);

        $this->assertNull($entidad->sigla);
    }

    // ─── Relationship ─────────────────────────────────────────────────────────

    public function test_pais_relationship_returns_pais_model(): void
    {
        $entidad = $this->createEntidad(['pais_codigo' => 'BO']);

        $pais = $entidad->pais;

        $this->assertInstanceOf(Pais::class, $pais);
        $this->assertSame('BO', $pais->codigo);
    }

    // ─── Fillable ────────────────────────────────────────────────────────────

    public function test_fillable_includes_required_fields(): void
    {
        $entidad = new EntidadPublica;

        $this->assertContains('pais_codigo', $entidad->getFillable());
        $this->assertContains('nombre', $entidad->getFillable());
        $this->assertContains('sigla', $entidad->getFillable());
        $this->assertContains('activo', $entidad->getFillable());
    }
}
