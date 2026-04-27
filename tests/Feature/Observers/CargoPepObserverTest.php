<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\CargoPep;
use App\Models\Pais;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests for CargoPepObserver — verifies cache invalidation on Eloquent CRUD.
 *
 * @note CargosPepBoliviaSeeder uses DB::table('cargos_pep')->updateOrInsert()
 *       which bypasses Eloquent events. The 5-min Cache TTL on PreFiltroService
 *       is the safety net for seeder runs. These observer tests cover the
 *       Eloquent-event path (runtime updates via application code).
 *
 * @see CargosPepBoliviaSeeder (database/seeders/CargosPepBoliviaSeeder.php)
 * @see App\Services\Gemini\PreFiltroService::CACHE_TTL
 */
class CargoPepObserverTest extends TestCase
{
    use RefreshDatabase;

    private Pais $pais;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Gemini so ResultadoScrapingObserver does not dispatch jobs
        // during test setup (QUEUE_CONNECTION=sync would run them synchronously).
        config(['services.gemini.enabled' => false]);

        // 'BO' is inserted by the paises migration (0001_01_01_000001_create_paises_table.php)
        // so it already exists after RefreshDatabase runs migrations. Just retrieve it.
        $this->pais = Pais::find('BO');

        // Prime the cache — simulates a warm pre-filtro-terms cache
        Cache::put('pre-filtro-terms', ['ministro', 'senador', 'fiscal'], 300);
    }

    // ────────────────────────────────────────────────────────────────────────────
    // 1.B.3 — Observer flush on create, update, delete
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Creating a CargoPep via Eloquent MUST flush 'pre-filtro-terms' from cache.
     *
     * RED: fails because CargoPepObserver does not exist yet.
     */
    public function test_observer_forgets_cache_on_create(): void
    {
        // Verify cache is warm before the operation
        $this->assertTrue(Cache::has('pre-filtro-terms'), 'Precondition: cache must be warm');

        // Perform Eloquent create (triggers observer)
        CargoPep::create($this->cargoPepData());

        // Observer must have flushed the cache
        $this->assertFalse(
            Cache::has('pre-filtro-terms'),
            'CargoPepObserver::created() must call Cache::forget("pre-filtro-terms")'
        );
    }

    /**
     * Updating a CargoPep via Eloquent MUST flush 'pre-filtro-terms' from cache.
     *
     * RED: fails because CargoPepObserver does not exist yet.
     */
    public function test_observer_forgets_cache_on_update(): void
    {
        // Bypass observer to insert without triggering flush
        $cargo = CargoPep::forceCreate($this->cargoPepData());

        // Re-prime the cache (was cleared by the create event in forceCreate)
        Cache::put('pre-filtro-terms', ['ministro', 'senador', 'fiscal'], 300);
        $this->assertTrue(Cache::has('pre-filtro-terms'), 'Precondition: cache must be warm after re-prime');

        // Perform Eloquent update (triggers observer)
        $cargo->update(['nombre' => 'director ejecutivo']);

        // Observer must have flushed the cache
        $this->assertFalse(
            Cache::has('pre-filtro-terms'),
            'CargoPepObserver::updated() must call Cache::forget("pre-filtro-terms")'
        );
    }

    /**
     * Deleting a CargoPep via Eloquent MUST flush 'pre-filtro-terms' from cache.
     *
     * RED: fails because CargoPepObserver does not exist yet.
     */
    public function test_observer_forgets_cache_on_delete(): void
    {
        // Create via DB insert to avoid triggering the create flush
        $cargo = CargoPep::forceCreate($this->cargoPepData());

        // Re-prime the cache
        Cache::put('pre-filtro-terms', ['ministro', 'senador', 'fiscal'], 300);
        $this->assertTrue(Cache::has('pre-filtro-terms'), 'Precondition: cache must be warm after re-prime');

        // Perform Eloquent delete (triggers observer)
        $cargo->delete();

        // Observer must have flushed the cache
        $this->assertFalse(
            Cache::has('pre-filtro-terms'),
            'CargoPepObserver::deleted() must call Cache::forget("pre-filtro-terms")'
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Helper
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function cargoPepData(): array
    {
        return [
            'pais_codigo'  => 'BO',
            'nombre'       => 'ministro de obras públicas',
            'categoria'    => 'ejecutivo',
            'entidad_tipo' => 'publica',
            'activo'       => true,
        ];
    }
}
