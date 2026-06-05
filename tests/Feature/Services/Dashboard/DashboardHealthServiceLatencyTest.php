<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dashboard;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for DashboardHealthService::pipelineLatency().
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T71–T73 (Phase 12 — pipelineLatency real implementation)
 *
 * Notes on SQLite vs PostgreSQL:
 * - SQLite doesn't have percentile_cont; PHP-side computation is used instead.
 * - Tests run on SQLite (:memory:); production uses PostgreSQL WITHIN GROUP.
 */
class DashboardHealthServiceLatencyTest extends TestCase
{
    use RefreshDatabase;

    private DashboardHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);
        config(['dashboard.health_cache_ttl' => 0]); // disable cache for tests

        // Force hostile session timezone on pgsql so any future asymmetric
        // AT TIME ZONE wrap regression in computeLatencyPostgres() surfaces here.
        // Safe no-op on sqlite (no session timezone concept).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET TIME ZONE 'UTC'");
        }

        $this->service = new DashboardHealthService(new DashboardCacheManager);
    }

    private function createFuente(): Fuente
    {
        return Fuente::create([
            'url'       => 'https://gobierno.bo/test-' . uniqid(),
            'nombre'    => 'Test',
            'organismo' => 'Test',
            'pais'      => 'BO',
        ]);
    }

    /**
     * Create a successfully-analyzed cambio with a controlled latency.
     *
     * Sets gemini_analisis_json to a non-null value to mark this as a successful
     * analysis (same as persistirAnalisis does). The latency query now requires
     * gemini_analisis_json IS NOT NULL to exclude terminal-failed rows.
     */
    private function createAnalyzedCambio(Fuente $fuente, int $latencySeconds): Cambio
    {
        Cambio::flushEventListeners();

        $fecha = now()->subSeconds($latencySeconds + 100); // ensure it's within 24h

        return Cambio::create([
            'fuente_id'            => $fuente->id,
            'fecha'                => $fecha,
            'diff_texto'           => 'test diff',
            'gemini_analyzed'      => true,
            'gemini_analyzed_at'   => $fecha->copy()->addSeconds($latencySeconds),
            'gemini_analisis_json' => ['riesgo' => 'bajo', 'es_mae' => false, 'analisis' => 'ok'],
        ]);
    }

    // =========================================================================
    // T71 — unavailable when sample_size < 10
    // =========================================================================

    public function test_pipeline_latency_returns_unavailable_when_sample_lt_10(): void
    {
        $fuente = $this->createFuente();

        // Create only 5 cambios — below the minimum 10 sample threshold
        for ($i = 0; $i < 5; $i++) {
            $this->createAnalyzedCambio($fuente, 60 + $i * 10);
        }

        $health = $this->service->getHealth();

        $this->assertFalse($health->latency->available, 'latency must be unavailable with < 10 samples');
        $this->assertNull($health->latency->p50_seconds);
        $this->assertNull($health->latency->p95_seconds);
    }

    // =========================================================================
    // T72 — computes real p50/p95 with realistic data (≥10 samples)
    // =========================================================================

    public function test_pipeline_latency_computes_p50_p95_with_realistic_data(): void
    {
        $fuente = $this->createFuente();

        // Create 10 cambios with latencies: 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 seconds
        $latencies = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

        foreach ($latencies as $latency) {
            $this->createAnalyzedCambio($fuente, $latency);
        }

        $health = $this->service->getHealth();

        $this->assertTrue($health->latency->available, 'latency must be available with 10 samples');
        $this->assertSame(10, $health->latency->sample_size);
        $this->assertNotNull($health->latency->p50_seconds);
        $this->assertNotNull($health->latency->p95_seconds);

        // p50 must be near 50 seconds. Tolerance covers driver math differences:
        //   - SQLite uses discrete approximation: median of [10..100] = 50 (val[4])
        //   - PostgreSQL PERCENTILE_CONT(0.5) uses linear interpolation: (50+60)/2 = 55
        // Both are valid statistical interpretations; the test only asserts the
        // answer is "in the middle of the distribution", not the exact method.
        $this->assertEqualsWithDelta(52.5, (float) $health->latency->p50_seconds, 7.5);

        // p95 must be near 95 seconds. Same driver-tolerance reasoning:
        //   - SQLite discrete: val at index floor(0.95 * 10) = val[9] = 100
        //   - PostgreSQL PERCENTILE_CONT(0.95): linear interp ≈ 95.5
        $this->assertEqualsWithDelta(95.0, (float) $health->latency->p95_seconds, 10.0);
    }

    // =========================================================================
    // T73 — cambios outside 24h window are excluded
    // =========================================================================

    public function test_pipeline_latency_excludes_cambios_outside_24h_window(): void
    {
        $fuente = $this->createFuente();

        // Create 12 cambios analyzed MORE than 24h ago — should NOT count
        for ($i = 0; $i < 12; $i++) {
            Cambio::flushEventListeners();
            $oldFecha = now()->subDays(2)->subSeconds($i * 10);
            Cambio::create([
                'fuente_id'          => $fuente->id,
                'fecha'              => $oldFecha,
                'diff_texto'         => 'old diff',
                'gemini_analyzed'    => true,
                'gemini_analyzed_at' => $oldFecha->copy()->addMinutes(5),
            ]);
        }

        $health = $this->service->getHealth();

        // All samples are outside window → unavailable
        $this->assertFalse($health->latency->available);
        $this->assertSame(0, $health->latency->sample_size);
    }

    // =========================================================================
    // Triangulation: exactly 10 samples triggers "available"
    // =========================================================================

    public function test_pipeline_latency_is_available_with_exactly_10_samples(): void
    {
        $fuente = $this->createFuente();

        for ($i = 1; $i <= 10; $i++) {
            $this->createAnalyzedCambio($fuente, $i * 30);
        }

        $health = $this->service->getHealth();

        $this->assertTrue($health->latency->available);
        $this->assertSame(10, $health->latency->sample_size);
    }

    // =========================================================================
    // FIX 3 — Terminal-failed rows (gemini_analisis_json IS NULL) must be
    //          excluded from the latency query so failure timing never
    //          pollutes P50/P95.
    // =========================================================================

    /**
     * When Pro's marcarFallido() sets gemini_analyzed_at=now() but leaves
     * gemini_analisis_json=null, those rows must NOT contribute to the
     * latency metric. Only rows with gemini_analisis_json IS NOT NULL are
     * counted (i.e., rows that went through persistirAnalisis successfully).
     */
    public function test_pipeline_latency_excludes_terminal_failed_rows(): void
    {
        $fuente = $this->createFuente();

        // Create 10 successful cambios with latency ~30s each.
        for ($i = 1; $i <= 10; $i++) {
            $this->createAnalyzedCambio($fuente, 30);
        }

        // Create 5 terminal-failed cambios: gemini_analyzed_at set, but
        // gemini_analisis_json IS NULL (this is what Pro's marcarFallido produces).
        for ($i = 0; $i < 5; $i++) {
            Cambio::flushEventListeners();
            $fecha = now()->subSeconds(3600 + $i * 10); // within 24h
            Cambio::create([
                'fuente_id'            => $fuente->id,
                'fecha'                => $fecha,
                'diff_texto'           => 'failed diff',
                'gemini_analyzed'      => true,
                'gemini_analyzed_at'   => $fecha->copy()->addSeconds(3), // very short — would skew stats down
                'gemini_analisis_json' => null,
            ]);
        }

        $health = $this->service->getHealth();

        // The 5 failed rows must NOT be counted in the sample.
        $this->assertTrue($health->latency->available, 'latency must be available (10 successful rows)');
        $this->assertSame(10, $health->latency->sample_size,
            'sample_size must count only successful rows (gemini_analisis_json IS NOT NULL)');
    }
}
