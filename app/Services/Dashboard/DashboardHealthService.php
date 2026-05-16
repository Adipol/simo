<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\LogScript;
use App\Models\User;
use App\Services\Dashboard\DTOs\GeminiQuotaDTO;
use App\Services\Dashboard\DTOs\LatencyDTO;
use App\Services\Dashboard\DTOs\PipelineHealthDTO;
use App\Services\Dashboard\DTOs\QueueDepthDTO;
use App\Services\Dashboard\DTOs\ScraperStatusDTO;
use App\Support\PgsqlTimezone;
use Illuminate\Support\Facades\DB;

final class DashboardHealthService
{
    private const CACHE_KEY = 'dashboard:health';

    public function __construct(
        private readonly DashboardCacheManager $cache,
    ) {}

    /**
     * Return the full pipeline health DTO.
     *
     * Cache strategy: the full payload (admin view) is cached globally.
     * can_see_details is injected POST-cache based on the requesting user,
     * so the same cached data is shared across all users.
     */
    public function getHealth(?User $user = null): PipelineHealthDTO
    {
        $ttl = (int) config('dashboard.health_cache_ttl', 15);

        /** @var PipelineHealthDTO $cached */
        $cached = $this->cache->remember(self::CACHE_KEY, $ttl, function (): PipelineHealthDTO {
            return new PipelineHealthDTO(
                scraper: $this->scraperStatus(),
                pep_monitor: $this->pepMonitorStatus(),
                queues: $this->queueDepth(),
                latency: $this->pipelineLatency(),
                quota: $this->geminiQuota(),
                // Temporarily false — will be replaced post-cache
                can_see_details: false,
            );
        });

        // Resolve can_see_details per-request (not cached, user-specific)
        return new PipelineHealthDTO(
            scraper: $cached->scraper,
            pep_monitor: $cached->pep_monitor,
            queues: $cached->queues,
            latency: $cached->latency,
            quota: $cached->quota,
            can_see_details: $this->canSeeDetails($user),
        );
    }

    // =========================================================================
    // Private computation methods
    // =========================================================================

    /**
     * Derive scraper status from the most recent LogScript entry.
     * States:
     *  - no_data  : no entry found
     *  - error    : last run had estado='error'
     *  - warning  : last run was > threshold hours ago
     *  - ok       : last run within threshold hours
     */
    private function scraperStatus(): ScraperStatusDTO
    {
        return $this->buildScraperStatus('scraper');
    }

    /**
     * Derive pep_monitor status from the most recent LogScript entry.
     */
    private function pepMonitorStatus(): ScraperStatusDTO
    {
        return $this->buildScraperStatus('pep_monitor');
    }

    /**
     * Shared logic for scraper/pep_monitor status derivation.
     */
    private function buildScraperStatus(string $scriptName): ScraperStatusDTO
    {
        $log = LogScript::ultimaEjecucion($scriptName);

        if ($log === null) {
            return ScraperStatusDTO::noData($scriptName);
        }

        $status = $this->deriveStatus($log);

        return new ScraperStatusDTO(
            name: $scriptName,
            state: (string) $log->estado,
            last_run: new \DateTimeImmutable($log->inicio->toDateTimeString()),
            duration_seconds: $log->duracion_segundos !== null ? (float) $log->duracion_segundos : null,
            status: $status,
        );
    }

    /**
     * Derive a health status string from a LogScript record.
     *
     * Priority:
     *   1. estado = 'error'  → 'error'
     *   2. inicio > threshold hours ago → 'warning'
     *   3. otherwise → 'ok'
     */
    private function deriveStatus(LogScript $log): string
    {
        if ($log->estado === 'error') {
            return 'error';
        }

        $thresholdHours = (int) config('dashboard.scraper_warning_threshold_hours', 6);

        if ($log->inicio->lt(now()->subHours($thresholdHours))) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Count pending jobs per queue name and partition into known + other buckets.
     */
    private function queueDepth(): QueueDepthDTO
    {
        $rows = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        return new QueueDepthDTO(
            gemini_pro_pending: (int) ($rows->get('gemini_pro')?->count ?? 0),
            gemini_flash_pending: (int) ($rows->get('gemini_flash')?->count ?? 0),
            other_pending: (int) $rows
                ->filter(fn ($r, $k) => ! in_array($k, ['gemini_pro', 'gemini_flash'], true))
                ->sum('count'),
        );
    }

    /**
     * Compute pipeline latency (P50/P95) from gemini_analyzed_at - fecha
     * for cambios analyzed in the last 24 hours.
     *
     * Requires at least 10 samples to report a meaningful result.
     * Uses a dual-driver strategy: PostgreSQL percentile_cont for production,
     * PHP-side computation for SQLite (:memory:) in tests.
     */
    private function pipelineLatency(): LatencyDTO
    {
        $ttl = (int) config('dashboard.health_cache_ttl', 15);

        /** @var LatencyDTO $cached */
        $cached = $this->cache->remember('dashboard:latency', $ttl, function (): LatencyDTO {
            return $this->computeLatency();
        });

        return $cached;
    }

    private function computeLatency(): LatencyDTO
    {
        if (DB::getDriverName() === 'pgsql') {
            return $this->computeLatencyPostgres();
        }

        return $this->computeLatencySqlite();
    }

    /**
     * PostgreSQL: use WITHIN GROUP ORDER BY for accurate server-side percentiles.
     */
    private function computeLatencyPostgres(): LatencyDTO
    {
        $fechaTz = PgsqlTimezone::normalize('fecha');

        $row = DB::selectOne(
            "SELECT
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (gemini_analyzed_at - fecha))) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (gemini_analyzed_at - fecha))) AS p95,
                COUNT(*) AS sample_size
             FROM cambios
             WHERE gemini_analyzed_at IS NOT NULL
               AND {$fechaTz} >= NOW() - INTERVAL '24 hours'"
        );

        $sampleSize = (int) ($row->sample_size ?? 0);

        if ($sampleSize < 10) {
            return new LatencyDTO(
                available: false,
                p50_seconds: null,
                p95_seconds: null,
                sample_size: $sampleSize,
            );
        }

        return new LatencyDTO(
            available: true,
            p50_seconds: $row->p50 !== null ? (float) $row->p50 : null,
            p95_seconds: $row->p95 !== null ? (float) $row->p95 : null,
            sample_size: $sampleSize,
        );
    }

    /**
     * SQLite fallback: fetch all diffs and compute percentiles in PHP.
     * SQLite's julianday() returns fractional days; multiply by 86400 for seconds.
     */
    private function computeLatencySqlite(): LatencyDTO
    {
        $rows = DB::select(
            "SELECT (julianday(gemini_analyzed_at) - julianday(fecha)) * 86400 AS diff_seconds
             FROM cambios
             WHERE gemini_analyzed_at IS NOT NULL
               AND fecha >= datetime('now', '-24 hours')
             ORDER BY diff_seconds ASC"
        );

        $count = count($rows);

        if ($count < 10) {
            return new LatencyDTO(
                available: false,
                p50_seconds: null,
                p95_seconds: null,
                sample_size: $count,
            );
        }

        $diffs = array_map(fn (\stdClass $r) => (float) $r->diff_seconds, $rows);

        $p50 = $this->percentile($diffs, 0.50);
        $p95 = $this->percentile($diffs, 0.95);

        return new LatencyDTO(
            available: true,
            p50_seconds: $p50,
            p95_seconds: $p95,
            sample_size: $count,
        );
    }

    /**
     * Compute the percentile value for an already-sorted array.
     *
     * @param  array<float>  $sorted  Values sorted ascending
     */
    private function percentile(array $sorted, float $quantile): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        // Nearest-rank method: take element at ceil(q * n), clamped to [1, n].
        $rank = (int) ceil($quantile * $count);
        $rank = max(1, min($rank, $count));

        return $sorted[$rank - 1];
    }

    /**
     * Aggregate today's Gemini token usage from gemini_usage_log.
     *
     * Returns unavailable when there are no calls today (can't calculate usage).
     * daily_limit comes from config('gemini.daily_token_limit') — null if not set.
     */
    private function geminiQuota(): GeminiQuotaDTO
    {
        $ttl = (int) config('dashboard.health_cache_ttl', 15);

        /** @var GeminiQuotaDTO $cached */
        $cached = $this->cache->remember('dashboard:quota', $ttl, function (): GeminiQuotaDTO {
            return $this->computeQuota();
        });

        return $cached;
    }

    private function computeQuota(): GeminiQuotaDTO
    {
        // Use Carbon's startOfDay() (PHP timezone-aware) instead of SQL CURRENT_DATE
        // which returns UTC date in SQLite/PG and silently breaks when app timezone
        // differs from UTC (e.g. America/La_Paz = UTC-4). Without this, rows inserted
        // with now() in local timezone don't match the CURRENT_DATE filter when CI
        // runs in the early UTC hours.
        $startOfToday = now()->startOfDay()->toDateTimeString();

        $row = DB::selectOne(
            'SELECT
                COALESCE(SUM(total_tokens), 0) AS tokens_today,
                COUNT(*) AS requests_today
             FROM gemini_usage_log
             WHERE created_at >= ?',
            [$startOfToday]
        );

        $requestsToday = (int) ($row->requests_today ?? 0);

        if ($requestsToday === 0) {
            return GeminiQuotaDTO::unavailable();
        }

        $dailyLimit = config('gemini.daily_token_limit');

        return new GeminiQuotaDTO(
            available: true,
            tokens_today: (int) ($row->tokens_today ?? 0),
            requests_today: $requestsToday,
            daily_limit: $dailyLimit !== null ? (int) $dailyLimit : null,
        );
    }

    /**
     * Resolve whether the requesting user may see pipeline details.
     */
    private function canSeeDetails(?User $user): bool
    {
        return $user?->can('ver dashboard estadisticas') ?? false;
    }
}
