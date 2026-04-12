<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\EntidadTipo;
use App\Models\CargoPep;
use App\Models\Pais;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargoPepTest extends TestCase
{
    use RefreshDatabase;

    private function createCargo(array $overrides = []): CargoPep
    {
        return CargoPep::create(array_merge([
            'pais_codigo' => 'BO',
            'nombre' => 'Ministro de Economía',
            'categoria' => 'Ejecutivo',
            'entidad_tipo' => 'publica',
            'activo' => true,
        ], $overrides));
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function test_scope_active_returns_only_active_cargos(): void
    {
        $this->createCargo(['activo' => true]);
        $this->createCargo(['nombre' => 'Cargo Inactivo', 'activo' => false]);

        $result = CargoPep::active()->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->activo);
    }

    public function test_scope_for_country_filters_by_pais_codigo(): void
    {
        $this->createCargo(['pais_codigo' => 'BO']);
        $this->createCargo(['nombre' => 'Cargo Honduras', 'pais_codigo' => 'HN']);

        $result = CargoPep::forCountry('BO')->get();

        $this->assertCount(1, $result);
        $this->assertSame('BO', $result->first()->pais_codigo);
    }

    public function test_scope_by_entidad_tipo_filters_by_enum(): void
    {
        $this->createCargo(['entidad_tipo' => 'todas', 'nombre' => 'Diputado']);
        $this->createCargo(['entidad_tipo' => 'publica', 'nombre' => 'Ministro']);
        $this->createCargo(['entidad_tipo' => 'ambas', 'nombre' => 'Gerente']);

        $result = CargoPep::byEntidadTipo(EntidadTipo::Publica)->get();

        $this->assertCount(1, $result);
        $this->assertSame('Ministro', $result->first()->nombre);
    }

    // ─── Casts ───────────────────────────────────────────────────────────────

    public function test_entidad_tipo_is_cast_to_enum(): void
    {
        $cargo = $this->createCargo(['entidad_tipo' => 'todas']);

        $this->assertInstanceOf(EntidadTipo::class, $cargo->entidad_tipo);
        $this->assertSame(EntidadTipo::Todas, $cargo->entidad_tipo);
    }

    public function test_activo_is_cast_to_boolean(): void
    {
        $cargo = $this->createCargo(['activo' => true]);

        $this->assertIsBool($cargo->activo);
        $this->assertTrue($cargo->activo);
    }

    // ─── Relationship ─────────────────────────────────────────────────────────

    public function test_pais_relationship_returns_pais_model(): void
    {
        $cargo = $this->createCargo(['pais_codigo' => 'BO']);

        $pais = $cargo->pais;

        $this->assertInstanceOf(Pais::class, $pais);
        $this->assertSame('BO', $pais->codigo);
    }

    // ─── Fillable ────────────────────────────────────────────────────────────

    public function test_fillable_includes_required_fields(): void
    {
        $cargo = new CargoPep;

        $this->assertContains('pais_codigo', $cargo->getFillable());
        $this->assertContains('nombre', $cargo->getFillable());
        $this->assertContains('categoria', $cargo->getFillable());
        $this->assertContains('entidad_tipo', $cargo->getFillable());
        $this->assertContains('activo', $cargo->getFillable());
    }
}
