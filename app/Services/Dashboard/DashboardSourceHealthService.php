<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Fuente;
use App\Models\LogFuenteRun;
use App\Services\Dashboard\DTOs\SourceHealthDTO;
use App\Services\Dashboard\DTOs\SourceHealthSummaryDTO;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates per-source scraper run health from log_fuente_runs.
 *
 * Core query: LATERAL JOIN (PostgreSQL) or correlated subquery (SQLite tests).
 * Status derivation uses configurable consecutive failure thresholds.
 */
final class DashboardSourceHealthService
{
    public function __construct(
        private readonly DashboardCacheManager $cache,
    ) {}

    /**
     * Returns an aggregate health summary for all active fuentes.
     * Results cached for summary_cache_ttl seconds.
     */
    public function getSummary(): SourceHealthSummaryDTO
    {
        $ttl = (int) config('dashboard.source_health.summary_cache_ttl', 60);
        $key = DashboardCacheManager::KEY_PREFIX.'source-health';

        /** @var SourceHealthSummaryDTO $result */
        $result = $this->cache->remember($key, $ttl, function (): SourceHealthSummaryDTO {
            return $this->computeSummary();
        });

        return $result;
    }

    /**
     * Returns detailed per-source health. Not cached (on-demand, ≤30ms budget).
     */
    public function getPerSourceStatus(int $fuenteId): SourceHealthDTO
    {
        $fuente = Fuente::find($fuenteId);

        if ($fuente === null) {
            throw new \InvalidArgumentException("Fuente {$fuenteId} not found");
        }

        $deadThreshold = (int) config('dashboard.source_health.consecutive_failures_dead', 10);

        $runs = LogFuenteRun::query()
            ->where('fuente_id', $fuenteId)
            ->orderBy('started_at', 'desc')
            ->limit($deadThreshold + 1)
            ->get(['estado', 'started_at']);

        if ($runs->isEmpty()) {
            return SourceHealthDTO::fromArray([
                'fuente_id' => $fuenteId,
                'nombre' => (string) $fuente->nombre,
                'status' => 'sin_info',
                'consecutive_failures' => 0,
                'last_run_at' => null,
                'last_ok_at' => null,
            ]);
        }

        $consecutiveFailures = $this->countConsecutiveFailures($runs->all());
        $status = $this->deriveStatus($consecutiveFailures);

        $lastRunAt = new \DateTimeImmutable((string) $runs->first()->started_at);

        // Find last ok
        $lastOkAt = null;
        foreach ($runs as $run) {
            if ($run->estado === 'success') {
                $lastOkAt = new \DateTimeImmutable((string) $run->started_at);
                break;
            }
        }

        return SourceHealthDTO::fromArray([
            'fuente_id' => $fuenteId,
            'nombre' => (string) $fuente->nombre,
            'status' => $status,
            'consecutive_failures' => $consecutiveFailures,
            'last_run_at' => $lastRunAt,
            'last_ok_at' => $lastOkAt,
        ]);
    }

    /**
     * Computes the aggregate summary using a single efficient query.
     * Uses LATERAL JOIN on PostgreSQL, correlated subquery on SQLite.
     */
    private function computeSummary(): SourceHealthSummaryDTO
    {
        $degradedThreshold = (int) config('dashboard.source_health.consecutive_failures_degraded', 3);
        $deadThreshold = (int) config('dashboard.source_health.consecutive_failures_dead', 10);

        // Get all active fuentes
        $fuentes = Fuente::query()
            ->where('activo', true)
            ->get(['id', 'nombre']);

        $total = $fuentes->count();

        if ($total === 0) {
            return SourceHealthSummaryDTO::unavailable();
        }

        $ok = 0;
        $degradadas = 0;
        $muertas = 0;
        $sinInfo = 0;

        // Fetch per-source recent runs in a single query (N fuentes × M rows per fuente)
        // For SQLite tests: fetch all recent rows, group in PHP
        // This avoids N+1 by fetching all needed rows at once
        $fuenteIds = $fuentes->pluck('id')->all();

        $runs = $this->fetchRecentRunsPerFuente($fuenteIds, $deadThreshold);

        foreach ($fuentes as $fuente) {
            $fuenteRuns = $runs[(int) $fuente->id] ?? [];

            if (empty($fuenteRuns)) {
                $sinInfo++;

                continue;
            }

            $consecutiveFailures = $this->countConsecutiveFailures($fuenteRuns);
            $status = $this->deriveStatus($consecutiveFailures, $degradedThreshold, $deadThreshold);

            match ($status) {
                'ok' => $ok++,
                'degradado' => $degradadas++,
                'muerto' => $muertas++,
                'sin_info' => $sinInfo++,
            };
        }

        return SourceHealthSummaryDTO::fromArray([
            'total_fuentes_activas' => $total,
            'ok' => $ok,
            'degradadas' => $degradadas,
            'muertas' => $muertas,
            'sin_info' => $sinInfo,
            'available' => true,
            'last_aggregation_at' => new \DateTimeImmutable,
        ]);
    }

    /**
     * Fetches the most recent N runs per fuente in a single query.
     * Uses ROW_NUMBER() OVER on PostgreSQL for true LATERAL semantics.
     * Falls back to a PHP-grouped approach on SQLite.
     *
     * @param  array<int>  $fuenteIds
     * @return array<int, array<object>>  keyed by fuente_id
     */
    private function fetchRecentRunsPerFuente(array $fuenteIds, int $limit): array
    {
        if (empty($fuenteIds)) {
            return [];
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            return $this->fetchRecentRunsPostgres($fuenteIds, $limit);
        }

        return $this->fetchRecentRunsSqlite($fuenteIds, $limit);
    }

    /**
     * PostgreSQL: single query using ROW_NUMBER() to get top-N per fuente.
     *
     * Uses dynamic IN (?, ?, ?, ...) placeholders instead of ANY(?) because PDO
     * cannot bind a PHP array to a single placeholder — it would attempt array-to-string
     * conversion and crash. The dynamic placeholder approach is safe: $placeholders
     * only contains literal '?' characters, all values pass through bindings.
     *
     * @param  array<int>  $fuenteIds
     * @return array<int, array<object>>
     */
    private function fetchRecentRunsPostgres(array $fuenteIds, int $limit): array
    {
        $placeholders = implode(',', array_fill(0, count($fuenteIds), '?'));

        $sql = <<<SQL
            SELECT fuente_id, estado, started_at
            FROM (
                SELECT
                    fuente_id,
                    estado,
                    started_at,
                    ROW_NUMBER() OVER (PARTITION BY fuente_id ORDER BY started_at DESC) AS rn
                FROM log_fuente_runs
                WHERE fuente_id IN ({$placeholders})
            ) ranked
            WHERE rn <= ?
            ORDER BY fuente_id, started_at DESC
            SQL;

        $bindings = array_merge($fuenteIds, [$limit]);

        $rows = DB::select($sql, $bindings);

        return $this->groupRunsByFuente($rows);
    }

    /**
     * SQLite fallback: fetch recent runs using a PHP-grouped approach.
     * Gets top-N rows per fuente via IN clause + ordered, then groups in PHP.
     *
     * @param  array<int>  $fuenteIds
     * @return array<int, array<object>>
     */
    private function fetchRecentRunsSqlite(array $fuenteIds, int $limit): array
    {
        // Fetch all rows ordered per fuente (SQLite doesn't support LATERAL or ANY())
        $rows = LogFuenteRun::query()
            ->whereIn('fuente_id', $fuenteIds)
            ->orderBy('fuente_id')
            ->orderBy('started_at', 'desc')
            ->get(['fuente_id', 'estado', 'started_at']);

        // Group and take top-N per fuente in PHP
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row->fuente_id;

            if (! isset($grouped[$id])) {
                $grouped[$id] = [];
            }

            if (count($grouped[$id]) < $limit) {
                $grouped[$id][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * @param  array<object>  $rows
     * @return array<int, array<object>>
     */
    private function groupRunsByFuente(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row->fuente_id;
            $grouped[$id][] = $row;
        }

        return $grouped;
    }

    /**
     * Counts consecutive failures from the tail (newest first).
     * Stops at first success.
     *
     * @param  array<object>  $runs  ordered newest-first
     */
    private function countConsecutiveFailures(array $runs): int
    {
        $failures = 0;

        foreach ($runs as $run) {
            if ($run->estado === 'success') {
                break;
            }

            $failures++;
        }

        return $failures;
    }

    /**
     * Derives status from consecutive failure count using configured thresholds.
     */
    private function deriveStatus(
        int $consecutiveFailures,
        ?int $degradedThreshold = null,
        ?int $deadThreshold = null,
    ): string {
        $degradedThreshold ??= (int) config('dashboard.source_health.consecutive_failures_degraded', 3);
        $deadThreshold ??= (int) config('dashboard.source_health.consecutive_failures_dead', 10);

        return match (true) {
            $consecutiveFailures >= $deadThreshold => 'muerto',
            $consecutiveFailures >= $degradedThreshold => 'degradado',
            default => 'ok',
        };
    }
}
