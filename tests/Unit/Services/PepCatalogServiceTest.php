<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CargoPep;
use App\Models\EntidadPublica;
use App\Services\Gemini\PepCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PepCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PepCatalogService::flushCache();
    }

    protected function tearDown(): void
    {
        PepCatalogService::flushCache();
        parent::tearDown();
    }

    private function seedCargos(string $pais = 'BO', int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            CargoPep::create([
                'pais_codigo' => $pais,
                'nombre' => "Cargo {$i}",
                'categoria' => 'Ejecutivo',
                'entidad_tipo' => 'publica',
                'activo' => true,
            ]);
        }
    }

    private function seedEntidades(string $pais = 'BO', int $count = 2): void
    {
        for ($i = 1; $i <= $count; $i++) {
            EntidadPublica::create([
                'pais_codigo' => $pais,
                'nombre' => "Entidad {$i}",
                'sigla' => "ENT{$i}",
                'activo' => true,
            ]);
        }
    }

    // ─── getCargos ─────────────────────────────────────────────────────────

    public function test_get_cargos_returns_collection(): void
    {
        $this->seedCargos('BO', 3);

        $service = new PepCatalogService;
        $result = $service->getCargos('BO');

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(3, $result);
    }

    public function test_get_cargos_returns_only_active_for_country(): void
    {
        $this->seedCargos('BO', 2);
        CargoPep::create([
            'pais_codigo' => 'BO',
            'nombre' => 'Cargo Inactivo',
            'categoria' => 'Ejecutivo',
            'entidad_tipo' => 'publica',
            'activo' => false,
        ]);

        $service = new PepCatalogService;
        $result = $service->getCargos('BO');

        $this->assertCount(2, $result);
    }

    // ─── getEntidades ──────────────────────────────────────────────────────

    public function test_get_entidades_returns_collection(): void
    {
        $this->seedEntidades('BO', 2);

        $service = new PepCatalogService;
        $result = $service->getEntidades('BO');

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    public function test_get_entidades_returns_empty_collection_for_unknown_country(): void
    {
        $service = new PepCatalogService;
        $result = $service->getEntidades('XX');

        // XX is not seeded, so must return empty
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    // ─── Static cache (N+1 prevention) ────────────────────────────────────

    public function test_get_cargos_executes_only_one_query_for_same_country(): void
    {
        $this->seedCargos('BO', 3);

        $service = new PepCatalogService;

        DB::enableQueryLog();

        $service->getCargos('BO');
        $service->getCargos('BO');
        $service->getCargos('BO');

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Only 1 query should have been executed (rest served from cache)
        $this->assertCount(1, $log);
    }

    public function test_get_entidades_executes_only_one_query_for_same_country(): void
    {
        $this->seedEntidades('BO', 2);

        $service = new PepCatalogService;

        DB::enableQueryLog();

        $service->getEntidades('BO');
        $service->getEntidades('BO');
        $service->getEntidades('BO');

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $log);
    }

    // ─── flushCache ────────────────────────────────────────────────────────

    public function test_flush_cache_clears_cargos_cache(): void
    {
        $this->seedCargos('BO', 2);

        $service = new PepCatalogService;
        $service->getCargos('BO'); // warm cache

        // Add a new cargo that is not in the cache
        CargoPep::create([
            'pais_codigo' => 'BO',
            'nombre' => 'Nuevo Cargo',
            'categoria' => 'Ejecutivo',
            'entidad_tipo' => 'publica',
            'activo' => true,
        ]);

        // Without flush — still 2
        $this->assertCount(2, $service->getCargos('BO'));

        // After flush — 3
        PepCatalogService::flushCache();
        $this->assertCount(3, $service->getCargos('BO'));
    }

    public function test_flush_cache_clears_entidades_cache(): void
    {
        $this->seedEntidades('BO', 1);

        $service = new PepCatalogService;
        $service->getEntidades('BO'); // warm cache

        EntidadPublica::create([
            'pais_codigo' => 'BO',
            'nombre' => 'Nueva Entidad',
            'sigla' => 'NE',
            'activo' => true,
        ]);

        // Without flush — still 1
        $this->assertCount(1, $service->getEntidades('BO'));

        // After flush — 2
        PepCatalogService::flushCache();
        $this->assertCount(2, $service->getEntidades('BO'));
    }
}
