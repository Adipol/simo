<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CategoriaFamilia;
use App\Models\FamiliaLema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamiliaLemaTest extends TestCase
{
    use RefreshDatabase;

    private function createFamilia(array $overrides = []): FamiliaLema
    {
        return FamiliaLema::create(array_merge([
            'raiz' => 'designar',
            'variantes' => ['designar', 'designación', 'designado'],
            'categoria' => 'designacion',
            'activo' => true,
        ], $overrides));
    }

    // ─── Casts ────────────────────────────────────────────────────────────────

    public function test_variantes_cast_returns_array(): void
    {
        $familia = $this->createFamilia();

        $this->assertIsArray($familia->variantes);
        $this->assertContains('designar', $familia->variantes);
        $this->assertContains('designación', $familia->variantes);
    }

    public function test_activo_cast_returns_bool(): void
    {
        $familia = $this->createFamilia(['activo' => true]);

        $this->assertIsBool($familia->activo);
        $this->assertTrue($familia->activo);
    }

    public function test_activo_false_cast_returns_bool(): void
    {
        $familia = $this->createFamilia(['activo' => false]);

        $this->assertIsBool($familia->activo);
        $this->assertFalse($familia->activo);
    }

    public function test_categoria_cast_returns_enum(): void
    {
        $familia = $this->createFamilia(['categoria' => 'designacion']);

        $this->assertInstanceOf(CategoriaFamilia::class, $familia->categoria);
        $this->assertSame(CategoriaFamilia::Designacion, $familia->categoria);
    }

    public function test_categoria_crimen_cast_returns_enum(): void
    {
        $familia = $this->createFamilia([
            'raiz' => 'detener',
            'categoria' => 'crimen',
        ]);

        $this->assertSame(CategoriaFamilia::Crimen, $familia->categoria);
    }

    // ─── active() scope ───────────────────────────────────────────────────────

    public function test_active_scope_returns_only_active_families(): void
    {
        $this->createFamilia(['raiz' => 'designar', 'activo' => true]);
        $this->createFamilia(['raiz' => 'nombrar', 'activo' => true]);
        $this->createFamilia(['raiz' => 'renunciar', 'categoria' => 'renuncia', 'activo' => true]);
        $this->createFamilia(['raiz' => 'cesar', 'categoria' => 'renuncia', 'activo' => false]);
        $this->createFamilia(['raiz' => 'detener', 'categoria' => 'crimen', 'activo' => false]);

        $actives = FamiliaLema::active()->get();

        $this->assertCount(3, $actives);
        $actives->each(fn ($f) => $this->assertTrue($f->activo));
    }

    // ─── byCategoria() scope ──────────────────────────────────────────────────

    public function test_by_categoria_scope_filters_by_string(): void
    {
        $this->createFamilia(['raiz' => 'designar', 'categoria' => 'designacion']);
        $this->createFamilia(['raiz' => 'nombrar', 'categoria' => 'designacion']);
        $this->createFamilia(['raiz' => 'renunciar', 'categoria' => 'renuncia']);

        $designaciones = FamiliaLema::byCategoria('designacion')->get();

        $this->assertCount(2, $designaciones);
    }

    public function test_by_categoria_scope_filters_by_enum(): void
    {
        $this->createFamilia(['raiz' => 'designar', 'categoria' => 'designacion']);
        $this->createFamilia(['raiz' => 'detener', 'categoria' => 'crimen']);
        $this->createFamilia(['raiz' => 'imputar', 'categoria' => 'crimen']);

        $crimenes = FamiliaLema::byCategoria(CategoriaFamilia::Crimen)->get();

        $this->assertCount(2, $crimenes);
    }
}
