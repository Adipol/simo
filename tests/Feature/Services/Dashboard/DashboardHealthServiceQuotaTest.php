<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dashboard;

use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for DashboardHealthService::geminiQuota().
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T74–T75 (Phase 12 — geminiQuota real implementation)
 */
class DashboardHealthServiceQuotaTest extends TestCase
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
        config(['gemini.daily_token_limit' => null]); // default: no limit configured

        $this->service = new DashboardHealthService(new DashboardCacheManager);
    }

    private function insertUsageLog(array $overrides = []): void
    {
        DB::table('gemini_usage_log')->insert(array_merge([
            'model'                 => 'gemini-2.5-flash',
            'prompt_tokens'         => 100,
            'completion_tokens'     => 50,
            'total_tokens'          => 150,
            'request_type'          => 'filtro',
            'cambio_id'             => null,
            'resultado_scraping_id' => null,
            'created_at'            => now(),
        ], $overrides));
    }

    // =========================================================================
    // T74 — aggregates today's tokens from gemini_usage_log
    // =========================================================================

    public function test_gemini_quota_aggregates_today_tokens(): void
    {
        $this->insertUsageLog(['total_tokens' => 1000, 'prompt_tokens' => 600, 'completion_tokens' => 400]);
        $this->insertUsageLog(['total_tokens' => 500,  'prompt_tokens' => 300, 'completion_tokens' => 200]);

        $health = $this->service->getHealth();

        $this->assertTrue($health->quota->available, 'quota must be available when requests exist today');
        $this->assertSame(1500, $health->quota->tokens_today);
        $this->assertSame(2, $health->quota->requests_today);
        $this->assertNull($health->quota->daily_limit); // no limit configured
    }

    // =========================================================================
    // T75 — returns unavailable when no requests today
    // =========================================================================

    public function test_gemini_quota_returns_unavailable_when_no_requests_today(): void
    {
        // No rows in gemini_usage_log

        $health = $this->service->getHealth();

        $this->assertFalse($health->quota->available, 'quota must be unavailable when no calls today');
        $this->assertNull($health->quota->tokens_today);
        $this->assertNull($health->quota->requests_today);
    }

    // =========================================================================
    // Triangulation: daily_limit from config
    // =========================================================================

    public function test_gemini_quota_includes_daily_limit_from_config(): void
    {
        config(['gemini.daily_token_limit' => 1_000_000]);

        $this->insertUsageLog(['total_tokens' => 250]);

        $health = $this->service->getHealth();

        $this->assertTrue($health->quota->available);
        $this->assertSame(1_000_000, $health->quota->daily_limit);
        $this->assertSame(250, $health->quota->tokens_today);
    }

    // =========================================================================
    // Triangulation: yesterday's logs are excluded
    // =========================================================================

    public function test_gemini_quota_excludes_yesterday_logs(): void
    {
        // Insert rows from yesterday
        $this->insertUsageLog([
            'total_tokens' => 9999,
            'created_at'   => now()->subDay(),
        ]);

        $health = $this->service->getHealth();

        // Yesterday's rows must not count — result must be unavailable (no today rows)
        $this->assertFalse($health->quota->available);
    }
}
