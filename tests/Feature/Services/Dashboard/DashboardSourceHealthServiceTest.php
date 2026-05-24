<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dashboard;

use App\Models\Fuente;
use App\Models\LogFuenteRun;
use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardSourceHealthService;
use App\Services\Dashboard\DTOs\SourceHealthDTO;
use App\Services\Dashboard\DTOs\SourceHealthSummaryDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature tests for DashboardSourceHealthService.
 * Uses dual-driver pattern: SQLite for test isolation, PostgreSQL paths verified separately.
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T4.1-T4.10 (Phase 4 — Service)
 */
class DashboardSourceHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardSourceHealthService $service;

    private DashboardCacheManager $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config(['dashboard.source_health.consecutive_failures_degraded' => 3]);
        config(['dashboard.source_health.consecutive_failures_dead' => 10]);
        config(['dashboard.source_health.summary_cache_ttl' => 60]);

        $this->cache = new DashboardCacheManager;
        $this->service = new DashboardSourceHealthService($this->cache);
    }

    // ─── T4.1: Empty case — no active fuentes ─────────────────────────────────

    public function test_get_summary_returns_unavailable_when_no_active_fuentes(): void
    {
        // No fuentes at all
        $summary = $this->service->getSummary();

        $this->assertInstanceOf(SourceHealthSummaryDTO::class, $summary);
        $this->assertFalse($summary->available);
        $this->assertSame(0, $summary->total_fuentes_activas);
    }

    public function test_get_summary_ignores_inactive_fuentes(): void
    {
        Fuente::factory()->create(['activo' => false]);

        $summary = $this->service->getSummary();

        $this->assertFalse($summary->available);
        $this->assertSame(0, $summary->total_fuentes_activas);
    }

    // ─── T4.2: All fuentes sin_info when no log rows ──────────────────────────

    public function test_all_fuentes_are_sin_info_when_no_runs_logged(): void
    {
        Fuente::factory()->count(3)->create(['activo' => true]);

        $summary = $this->service->getSummary();

        $this->assertTrue($summary->available);
        $this->assertSame(3, $summary->total_fuentes_activas);
        $this->assertSame(0, $summary->ok);
        $this->assertSame(0, $summary->degradadas);
        $this->assertSame(0, $summary->muertas);
        $this->assertSame(3, $summary->sin_info);
    }

    // ─── T4.3: 2 consecutive failures → ok (below threshold of 3) ────────────

    public function test_two_consecutive_failures_are_below_degraded_threshold(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        // 2 consecutive failures
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(2));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(1));

        $summary = $this->service->getSummary();

        $this->assertSame(1, $summary->ok);
        $this->assertSame(0, $summary->degradadas);
        $this->assertSame(0, $summary->muertas);
    }

    // ─── T4.4: 3 consecutive failures → degradado ────────────────────────────

    public function test_three_consecutive_failures_yields_degradado(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(3));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(2));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(1));

        $summary = $this->service->getSummary();

        $this->assertSame(0, $summary->ok);
        $this->assertSame(1, $summary->degradadas);
        $this->assertSame(0, $summary->muertas);
    }

    // ─── T4.5: 10 consecutive failures → muerto ───────────────────────────────

    public function test_ten_consecutive_failures_yields_muerto(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        for ($i = 10; $i >= 1; $i--) {
            $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes($i));
        }

        $summary = $this->service->getSummary();

        $this->assertSame(0, $summary->ok);
        $this->assertSame(0, $summary->degradadas);
        $this->assertSame(1, $summary->muertas);
    }

    // ─── T4.6: Success breaks racha → back to ok ──────────────────────────────

    public function test_success_after_failures_resets_to_ok(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        // 5 failures then success — success is most recent
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(6));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(5));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(4));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(3));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(2));
        $this->createRun($fuente->id, 'success', $now->copy()->subMinutes(1));

        $summary = $this->service->getSummary();

        $this->assertSame(1, $summary->ok);
        $this->assertSame(0, $summary->degradadas);
        $this->assertSame(0, $summary->muertas);
    }

    public function test_failures_after_success_count_only_from_most_recent(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        // success then 3 failures (newest = failures)
        $this->createRun($fuente->id, 'success', $now->copy()->subMinutes(4));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(3));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(2));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(1));

        $summary = $this->service->getSummary();

        // 3 consecutive failures from head → degradado
        $this->assertSame(1, $summary->degradadas);
        $this->assertSame(0, $summary->ok);
    }

    // ─── T4.7: Cache hit/miss ─────────────────────────────────────────────────

    public function test_cache_is_used_on_second_call(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);

        // First call — populates cache
        $first = $this->service->getSummary();

        // Create a new run that would change the result
        $this->createRun($fuente->id, 'http_error', now());

        // Second call — should still return cached result
        $second = $this->service->getSummary();

        // Both DTOs should be the same (cache hit)
        $this->assertSame($first->sin_info, $second->sin_info);
        $this->assertSame($first->ok, $second->ok);
    }

    public function test_cache_miss_reflects_new_data(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);

        // First call with no runs
        $first = $this->service->getSummary();
        $this->assertSame(1, $first->sin_info);

        // Bust cache manually
        Cache::forget(DashboardCacheManager::KEY_PREFIX.'source-health');

        // Add 3 failures
        $now = now();
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(3));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(2));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(1));

        // Second call — fresh data
        $second = $this->service->getSummary();
        $this->assertSame(0, $second->sin_info);
        $this->assertSame(1, $second->degradadas);
    }

    // ─── T4.8: DTO invariant ─────────────────────────────────────────────────

    public function test_dto_invariant_ok_plus_degradadas_plus_muertas_plus_sin_info_equals_total(): void
    {
        // Mixed scenario: 3 fuentes with different statuses
        $fuente1 = Fuente::factory()->create(['activo' => true]);
        $fuente2 = Fuente::factory()->create(['activo' => true]);
        $fuente3 = Fuente::factory()->create(['activo' => true]);
        $now = now();

        // fuente1: 3 consecutive failures → degradado
        $this->createRun($fuente1->id, 'http_error', $now->copy()->subMinutes(3));
        $this->createRun($fuente1->id, 'http_error', $now->copy()->subMinutes(2));
        $this->createRun($fuente1->id, 'http_error', $now->copy()->subMinutes(1));

        // fuente2: 1 success → ok
        $this->createRun($fuente2->id, 'success', $now->copy()->subMinutes(1));

        // fuente3: no runs → sin_info

        $summary = $this->service->getSummary();

        $this->assertSame(3, $summary->total_fuentes_activas);
        $this->assertSame(
            $summary->total_fuentes_activas,
            $summary->ok + $summary->degradadas + $summary->muertas + $summary->sin_info,
            'Invariant: ok + degradadas + muertas + sin_info must equal total'
        );
    }

    // ─── T4.9: getPerSourceStatus ─────────────────────────────────────────────

    public function test_get_per_source_status_returns_source_health_dto(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true, 'nombre' => 'Test Source']);
        $now = now();

        $this->createRun($fuente->id, 'success', $now->copy()->subMinutes(2));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(1));

        $dto = $this->service->getPerSourceStatus($fuente->id);

        $this->assertInstanceOf(SourceHealthDTO::class, $dto);
        $this->assertSame($fuente->id, $dto->fuente_id);
        $this->assertSame('Test Source', $dto->nombre);
        // 1 consecutive failure (from the tail) → ok (below threshold of 3)
        $this->assertSame('ok', $dto->status);
        $this->assertSame(1, $dto->consecutive_failures);
    }

    public function test_get_per_source_status_returns_sin_info_for_new_fuente(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true, 'nombre' => 'New Source']);

        $dto = $this->service->getPerSourceStatus($fuente->id);

        $this->assertSame('sin_info', $dto->status);
        $this->assertSame(0, $dto->consecutive_failures);
        $this->assertNull($dto->last_run_at);
    }

    // ─── T4.10: Performance budget ───────────────────────────────────────────

    public function test_performance_cold_cache_under_100ms_with_24_fuentes(): void
    {
        // Create 24 fuentes with 100 runs each
        $fuentes = Fuente::factory()->count(24)->create(['activo' => true]);
        $now = now();

        foreach ($fuentes as $fuente) {
            for ($i = 100; $i >= 1; $i--) {
                $this->createRun(
                    $fuente->id,
                    $i % 5 === 0 ? 'success' : 'http_error',
                    $now->copy()->subMinutes($i)
                );
            }
        }

        Cache::flush();

        $start = microtime(true);
        $this->service->getSummary();
        $elapsed = (microtime(true) - $start) * 1000; // ms

        $this->assertLessThan(100, $elapsed, "Cold cache took {$elapsed}ms — must be ≤100ms");
    }

    public function test_performance_warm_cache_under_10ms(): void
    {
        Fuente::factory()->count(24)->create(['activo' => true]);

        // Warm cache
        $this->service->getSummary();

        $start = microtime(true);
        $this->service->getSummary();
        $elapsed = (microtime(true) - $start) * 1000; // ms

        $this->assertLessThan(10, $elapsed, "Warm cache took {$elapsed}ms — must be ≤10ms");
    }

    // ─── T4.11–T4.16: Healthy-estado classification ───────────────────────────

    public function test_single_no_change_run_is_zero_failures_and_status_ok(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        $this->createRun($fuente->id, 'no_change', $now->copy()->subMinutes(1));

        $dto = $this->service->getPerSourceStatus($fuente->id);

        $this->assertSame(0, $dto->consecutive_failures);
        $this->assertSame('ok', $dto->status);

        $summary = $this->service->getSummary();
        $this->assertSame(1, $summary->ok);
        $this->assertSame(0, $summary->muertas);
    }

    public function test_fifteen_consecutive_no_change_runs_remain_ok_not_muerto(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        for ($i = 15; $i >= 1; $i--) {
            $this->createRun($fuente->id, 'no_change', $now->copy()->subMinutes($i));
        }

        $summary = $this->service->getSummary();

        $this->assertSame(1, $summary->ok);
        $this->assertSame(0, $summary->muertas);
        $this->assertSame(0, $summary->degradadas);
    }

    public function test_first_snapshot_run_is_not_a_failure(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        $this->createRun($fuente->id, 'first_snapshot', $now->copy()->subMinutes(1));

        $dto = $this->service->getPerSourceStatus($fuente->id);

        $this->assertSame(0, $dto->consecutive_failures);
        $this->assertSame('ok', $dto->status);
    }

    public function test_no_content_run_counts_as_failure(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        $this->createRun($fuente->id, 'no_content', $now->copy()->subMinutes(1));

        $dto = $this->service->getPerSourceStatus($fuente->id);

        $this->assertSame(1, $dto->consecutive_failures);
        $this->assertSame('ok', $dto->status); // 1 < degraded threshold of 3
    }

    public function test_mixed_sequence_failure_streak_stops_at_first_healthy_estado(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        // Insert oldest-first so newest-first order is: http_error(-1m), timeout(-2m), no_change(-3m), http_error(-4m), parse_error(-5m)
        $this->createRun($fuente->id, 'parse_error', $now->copy()->subMinutes(5));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(4));
        $this->createRun($fuente->id, 'no_change', $now->copy()->subMinutes(3));
        $this->createRun($fuente->id, 'timeout', $now->copy()->subMinutes(2));
        $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes(1));

        $dto = $this->service->getPerSourceStatus($fuente->id);

        // Algorithm stops at no_change (index 2 newest-first): counts http_error + timeout = 2
        $this->assertSame(2, $dto->consecutive_failures);
        $this->assertSame('ok', $dto->status); // 2 < degraded threshold of 3
    }

    public function test_last_ok_at_is_set_when_most_recent_run_is_no_change(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        $oldest = $now->copy()->subMinutes(3);
        $middle = $now->copy()->subMinutes(2);
        $newest = $now->copy()->subMinutes(1);

        $this->createRun($fuente->id, 'no_change', $oldest);
        $this->createRun($fuente->id, 'no_change', $middle);
        $this->createRun($fuente->id, 'no_change', $newest);

        $dto = $this->service->getPerSourceStatus($fuente->id);

        $this->assertNotNull($dto->last_ok_at);
        $this->assertSame(
            $newest->format('Y-m-d H:i:s'),
            $dto->last_ok_at->format('Y-m-d H:i:s'),
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createRun(
        int $fuenteId,
        string $estado,
        \Carbon\Carbon $startedAt,
    ): LogFuenteRun {
        return LogFuenteRun::create([
            'fuente_id' => $fuenteId,
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => $startedAt->copy()->addSeconds(2)->toDateTimeString(),
            'estado' => $estado,
            'cambios_detectados' => 0,
            'duracion_segundos' => 2.0,
        ]);
    }
}
