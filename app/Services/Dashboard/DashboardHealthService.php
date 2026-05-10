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
                latency: $this->latencyStub(),
                quota: $this->quotaStub(),
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
     * PR1 stub — latency monitoring not yet implemented.
     */
    private function latencyStub(): LatencyDTO
    {
        return LatencyDTO::unavailable();
    }

    /**
     * PR1 stub — Gemini quota tracking not yet implemented.
     */
    private function quotaStub(): GeminiQuotaDTO
    {
        return GeminiQuotaDTO::unavailable();
    }

    /**
     * Resolve whether the requesting user may see pipeline details.
     *
     * @param  ?User  $user
     */
    private function canSeeDetails(?User $user): bool
    {
        return $user?->can('ver dashboard estadisticas') ?? false;
    }
}
