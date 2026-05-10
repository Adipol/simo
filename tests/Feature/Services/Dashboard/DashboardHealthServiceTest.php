<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dashboard;

use App\Models\LogScript;
use App\Models\User;
use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardHealthService;
use App\Services\Dashboard\DTOs\PipelineHealthDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Feature tests for DashboardHealthService.
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T25-T29 (Phase 4 — DashboardHealthService)
 */
class DashboardHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardHealthService $service;
    private DashboardCacheManager $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Disable external services so observers don't interfere
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        config(['dashboard.health_cache_ttl' => 15]);
        config(['dashboard.scraper_warning_threshold_hours' => 6]);

        $this->cache   = new DashboardCacheManager();
        $this->service = new DashboardHealthService($this->cache);
    }

    // =========================================================================
    // T25 — getHealth() returns PipelineHealthDTO
    // =========================================================================

    public function test_get_health_returns_pipeline_health_dto(): void
    {
        $health = $this->service->getHealth();

        $this->assertInstanceOf(PipelineHealthDTO::class, $health);
    }

    // =========================================================================
    // T26 — Scraper status: ok when last run within threshold
    // =========================================================================

    public function test_scraper_status_is_ok_when_last_run_within_threshold(): void
    {
        LogScript::factory()->scraper()->reciente(30)->create();

        $health = $this->service->getHealth();

        $this->assertSame('ok', $health->scraper->status);
        $this->assertSame('scraper', $health->scraper->name);
        $this->assertNotNull($health->scraper->last_run);
    }

    public function test_scraper_status_is_warning_when_last_run_exceeds_threshold(): void
    {
        // Last run 8 hours ago, threshold is 6
        LogScript::factory()->scraper()->haceHoras(8)->create();

        $health = $this->service->getHealth();

        $this->assertSame('warning', $health->scraper->status);
    }

    public function test_scraper_status_is_no_data_when_no_log_exists(): void
    {
        // No log entries at all
        $health = $this->service->getHealth();

        $this->assertSame('no_data', $health->scraper->status);
        $this->assertNull($health->scraper->last_run);
    }

    // =========================================================================
    // T27 — PEP Monitor status
    // =========================================================================

    public function test_pep_monitor_status_is_ok_when_last_run_within_threshold(): void
    {
        LogScript::factory()->pepMonitor()->reciente(60)->create();

        $health = $this->service->getHealth();

        $this->assertSame('ok', $health->pep_monitor->status);
        $this->assertSame('pep_monitor', $health->pep_monitor->name);
    }

    public function test_pep_monitor_status_is_warning_when_stale(): void
    {
        LogScript::factory()->pepMonitor()->haceHoras(10)->create();

        $health = $this->service->getHealth();

        $this->assertSame('warning', $health->pep_monitor->status);
    }

    // =========================================================================
    // T28 — Queue depth
    // =========================================================================

    public function test_queue_depth_is_all_zeros_when_jobs_table_empty(): void
    {
        $health = $this->service->getHealth();

        $this->assertSame(0, $health->queues->gemini_pro_pending);
        $this->assertSame(0, $health->queues->gemini_flash_pending);
        $this->assertSame(0, $health->queues->other_pending);
    }

    public function test_queue_depth_groups_correctly_by_queue_name(): void
    {
        $now = now()->timestamp;

        DB::table('jobs')->insert([
            ['queue' => 'gemini_pro',   'payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now],
            ['queue' => 'gemini_pro',   'payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now],
            ['queue' => 'gemini_flash', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now],
            ['queue' => 'default',      'payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now],
            ['queue' => 'notifications','payload' => '{}', 'attempts' => 0, 'available_at' => $now, 'created_at' => $now],
        ]);

        $health = $this->service->getHealth();

        $this->assertSame(2, $health->queues->gemini_pro_pending);
        $this->assertSame(1, $health->queues->gemini_flash_pending);
        $this->assertSame(2, $health->queues->other_pending);
    }

    // =========================================================================
    // T29 — Latency stub returns available:false
    // =========================================================================

    public function test_latency_is_unavailable_stub_in_pr1(): void
    {
        $health = $this->service->getHealth();

        $this->assertFalse($health->latency->available);
        $this->assertNull($health->latency->p50_seconds);
        $this->assertNull($health->latency->p95_seconds);
        $this->assertSame(0, $health->latency->sample_size);
    }

    // =========================================================================
    // T30 — Quota stub returns available:false
    // =========================================================================

    public function test_quota_is_unavailable_stub_in_pr1(): void
    {
        $health = $this->service->getHealth();

        $this->assertFalse($health->quota->available);
        $this->assertNull($health->quota->tokens_today);
        $this->assertNull($health->quota->requests_today);
        $this->assertNull($health->quota->daily_limit);
    }

    // =========================================================================
    // T31 — canSeeDetails: admin user → true, operator → false, null → false
    // =========================================================================

    public function test_can_see_details_is_true_for_user_with_permission(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'ver dashboard estadisticas', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->givePermissionTo($permission);

        $health = $this->service->getHealth($admin);

        $this->assertTrue($health->can_see_details);
    }

    public function test_can_see_details_is_false_for_user_without_permission(): void
    {
        $operator = User::factory()->create();

        $health = $this->service->getHealth($operator);

        $this->assertFalse($health->can_see_details);
    }

    public function test_can_see_details_is_false_for_null_user(): void
    {
        $health = $this->service->getHealth(null);

        $this->assertFalse($health->can_see_details);
    }

    // =========================================================================
    // T32 — Cache: health is cached globally; can_see_details resolved post-cache
    // =========================================================================

    public function test_health_is_cached_and_can_see_details_is_user_specific(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'ver dashboard estadisticas', 'guard_name' => 'web']);

        $admin    = User::factory()->create();
        $operator = User::factory()->create();

        $admin->givePermissionTo($permission);

        LogScript::factory()->scraper()->reciente(30)->create();

        // First call — admin sees details
        $healthAdmin    = $this->service->getHealth($admin);
        // Second call — operator does not
        $healthOperator = $this->service->getHealth($operator);

        $this->assertTrue($healthAdmin->can_see_details);
        $this->assertFalse($healthOperator->can_see_details);

        // Both must see same scraper status (shared cache)
        $this->assertSame($healthAdmin->scraper->status, $healthOperator->scraper->status);
    }

    // =========================================================================
    // T33 — Error state: last run with error estado
    // =========================================================================

    public function test_scraper_status_is_error_when_last_run_has_error_estado(): void
    {
        LogScript::factory()->scraper()->conError('Conexión fallida')->create();

        $health = $this->service->getHealth();

        $this->assertSame('error', $health->scraper->status);
    }
}
