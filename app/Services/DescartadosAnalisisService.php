<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ResultadoScraping;
use App\Services\Dashboard\DTOs\ConfianzaBucketDTO;
use App\Services\Dashboard\DTOs\DescartadosMetricsDTO;
use App\Services\Dashboard\DTOs\DriftDTO;
use App\Services\Dashboard\DTOs\KeywordAnalisisDTO;
use App\Services\Dashboard\DTOs\SitioAnalisisDTO;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation engine for discarded scraping results.
 *
 * Sibling of DashboardMetricsService — NOT an extension. Reads IMPLICIT
 * operator feedback (descartado / relevante flags) rather than the EXPLICIT
 * clasificaciones_feedback table. Keeps signal sources separate by design.
 *
 * feedback-loop-from-descartados · design §Service API
 */
final class DescartadosAnalisisService
{
    private const CACHE_TTL = 300;
    private const MIN_SAMPLE_KEYWORD = 5;
    private const MIN_SAMPLE_GLOBAL = 10;

    /**
     * Registry of all cached method specs.
     *
     * Both cacheKey() and flushCache() iterate this constant so the two are
     * always in sync — no key can be cached without also being flushable.
     *
     * Format: 'spec_name' => 'descartados:{method}:{param1}:{param2}...'
     * The params portion uses printf placeholders (%d) for runtime values.
     *
     * CACHED_KEY_SPECS maps to the DEFAULT parameter set used by flushCache().
     */
    private const CACHED_KEY_SPECS = [
        'precision' => 'descartados:precision:%d',
        'lemas'     => 'descartados:lemas:%d:%d',
        'sitios'    => 'descartados:sitios:%d:%d',
        'drift'     => 'descartados:drift:%d:%d',
        'confianza' => 'descartados:confianza:%d',
    ];

    /** Default parameter sets for flushCache() — mirrors method defaults. */
    private const FLUSH_DEFAULT_PARAMS = [
        'precision' => [30],
        'lemas'     => [30, 5],
        'sitios'    => [30, 5],
        'drift'     => [30, 30],
        'confianza' => [30],
    ];

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Calculate overall scraping precision over labeled rows.
     *
     * REQ-1 / SCN-1.1–1.4
     *
     * Labeled = descartado=true OR relevante=true. Unlabeled excluded.
     * Guard: if labeled < MIN_SAMPLE_GLOBAL → returns DTO with precisionPct=null.
     *
     * @param  int  $dias       Lookback window in days (default 30)
     * @param  int  $minGlobal  Minimum labeled sample required (default 10)
     * @param  bool $skipCache  When true: bypass cache, always query DB
     */
    public function precisionGeneral(int $dias = 30, int $minGlobal = 10, bool $skipCache = false): DescartadosMetricsDTO
    {
        $key = $this->cacheKey('precision', $dias);

        if ($skipCache) {
            return $this->computePrecisionGeneral($dias, $minGlobal);
        }

        return $this->cache->remember($key, self::CACHE_TTL, fn () => $this->computePrecisionGeneral($dias, $minGlobal));
    }

    /**
     * Return keywords ranked by % descartado (highest noise first).
     *
     * REQ-2 / SCN-2.1–2.3
     *
     * Keywords with fewer than $minSample total rows are excluded.
     * Returns Collection<KeywordAnalisisDTO>.
     */
    public function topLemasProblematicos(int $dias = 30, int $minSample = 5, int $limit = 10, bool $skipCache = false): Collection
    {
        $key = $this->cacheKey('lemas', $dias, $minSample);

        if ($skipCache) {
            return $this->computeTopLemas($dias, $minSample, $limit);
        }

        return $this->cache->remember($key, self::CACHE_TTL, fn () => $this->computeTopLemas($dias, $minSample, $limit));
    }

    /**
     * Return sitios ranked by % descartado (highest noise first).
     *
     * REQ-3 / SCN-3.1–3.2
     *
     * JOINs sitios_web for display name. Sitios below $minSample excluded.
     * Returns Collection<SitioAnalisisDTO>.
     */
    public function topSitiosProblematicos(int $dias = 30, int $minSample = 5, int $limit = 10, bool $skipCache = false): Collection
    {
        $key = $this->cacheKey('sitios', $dias, $minSample);

        if ($skipCache) {
            return $this->computeTopSitios($dias, $minSample, $limit);
        }

        return $this->cache->remember($key, self::CACHE_TTL, fn () => $this->computeTopSitios($dias, $minSample, $limit));
    }

    /**
     * Compare % descartado per keyword: current (0–$ventanaRecent d) vs
     * previous ($ventanaRecent–($ventanaRecent+$ventanaPrevious) d).
     *
     * REQ-4 / SCN-4.1–4.3
     *
     * Uses raw SQL with CTEs for clarity — Eloquent CTE support is too verbose
     * and varies across drivers. The query is driver-agnostic (SQLite + pgsql).
     *
     * Returns Collection<DriftDTO>. pctAnterior=null when previous window is empty.
     */
    public function driftPorKeyword(int $ventanaRecent = 30, int $ventanaPrevious = 30, int $minSample = 5, bool $skipCache = false): Collection
    {
        $key = $this->cacheKey('drift', $ventanaRecent, $ventanaPrevious);

        if ($skipCache) {
            return $this->computeDrift($ventanaRecent, $ventanaPrevious, $minSample);
        }

        return $this->cache->remember($key, self::CACHE_TTL, fn () => $this->computeDrift($ventanaRecent, $ventanaPrevious, $minSample));
    }

    /**
     * Bucket rows by gemini_confianza and report human discard rate per bucket.
     *
     * REQ-5 / SCN-5.1–5.3
     *
     * Frozen bucket boundaries: 0-49, 50-69, 70-84, 85-100.
     * Returns Collection of arrays with keys: bucket, total, descartados, pct_descartado.
     */
    public function confianzaGeminiVsDescartado(int $dias = 30, bool $skipCache = false): Collection
    {
        $key = $this->cacheKey('confianza', $dias);

        if ($skipCache) {
            return $this->computeConfianza($dias);
        }

        return $this->cache->remember($key, self::CACHE_TTL, fn () => $this->computeConfianza($dias));
    }

    /**
     * Return high-confidence descartados as negative examples for T3 auto-feedback.
     *
     * REQ-7 / SCN-7.1–7.2
     *
     * NOT cached — this seam is not consumed by T1 or T2 today. It is exposed
     * for future T3 integration only. High-confidence = gemini_confianza >= 70.
     */
    public function getNegativeExamples(int $limit = 10): Collection
    {
        return ResultadoScraping::where('descartado', true)
            ->where('gemini_confianza', '>=', 70)
            ->orderBy('gemini_confianza', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Flush all known cache keys with default parameters.
     *
     * REQ-6 — Iterates CACHED_KEY_SPECS and FLUSH_DEFAULT_PARAMS in lockstep,
     * so any new method added to the registry is automatically flushed.
     *
     * Limitation: only flushes the DEFAULT parameter combination. Calls with
     * custom params (e.g. dias=60) will see a cache miss on next call and
     * self-heal within one TTL window. Cache tags would solve this completely
     * but are unsupported by the default cache driver.
     */
    public function flushCache(): void
    {
        foreach (self::CACHED_KEY_SPECS as $spec => $pattern) {
            $params = self::FLUSH_DEFAULT_PARAMS[$spec] ?? [];
            $key = sprintf($pattern, ...$params);
            $this->cache->forget($key);
        }
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    /**
     * Build a cache key from a spec name and runtime integer parameters.
     *
     * Uses CACHED_KEY_SPECS as the single source of truth for key format.
     */
    private function cacheKey(string $spec, int ...$args): string
    {
        return sprintf(self::CACHED_KEY_SPECS[$spec], ...$args);
    }

    /**
     * REQ-1: Compute overall precision from labeled rows only.
     */
    private function computePrecisionGeneral(int $dias, int $minGlobal): DescartadosMetricsDTO
    {
        $since = Carbon::now()->subDays($dias);

        $row = DB::table('resultados_scraping')
            ->where('fecha_encontrado', '>=', $since)
            ->where(function ($q): void {
                $q->where('descartado', true)
                  ->orWhere('relevante', true);
            })
            ->selectRaw('
                COUNT(*) AS total_procesados,
                SUM(CASE WHEN descartado IS TRUE THEN 1 ELSE 0 END) AS total_descartados,
                SUM(CASE WHEN relevante IS TRUE THEN 1 ELSE 0 END) AS total_relevantes,
                SUM(CASE WHEN archivado_at IS NOT NULL THEN 1 ELSE 0 END) AS total_archivados
            ')
            ->first();

        $totalProcesados  = (int) ($row?->total_procesados ?? 0);
        $totalDescartados = (int) ($row?->total_descartados ?? 0);
        $totalRelevantes  = (int) ($row?->total_relevantes ?? 0);
        $totalArchivados  = (int) ($row?->total_archivados ?? 0);

        if ($totalProcesados < $minGlobal) {
            return new DescartadosMetricsDTO(
                totalProcesados: $totalProcesados,
                totalDescartados: $totalDescartados,
                totalRelevantes: $totalRelevantes,
                totalArchivados: $totalArchivados,
                precisionPct: null,
                insufficientReason: "Datos insuficientes: {$totalProcesados} filas etiquetadas (mínimo {$minGlobal} requerido para calcular precisión)",
            );
        }

        $labeled = $totalDescartados + $totalRelevantes;
        $precisionPct = $labeled > 0
            ? round(($totalRelevantes / $labeled) * 100, 1)
            : 0.0;

        return new DescartadosMetricsDTO(
            totalProcesados: $totalProcesados,
            totalDescartados: $totalDescartados,
            totalRelevantes: $totalRelevantes,
            totalArchivados: $totalArchivados,
            precisionPct: $precisionPct,
        );
    }

    /**
     * REQ-2: Rank keywords by % descartado, excluding small-N keywords.
     */
    private function computeTopLemas(int $dias, int $minSample, int $limit): Collection
    {
        $since = Carbon::now()->subDays($dias);

        $rows = DB::table('resultados_scraping')
            ->where('fecha_encontrado', '>=', $since)
            ->selectRaw('
                keyword,
                COUNT(*) AS total,
                SUM(CASE WHEN descartado IS TRUE THEN 1 ELSE 0 END) AS descartados,
                SUM(CASE WHEN relevante IS TRUE THEN 1 ELSE 0 END) AS relevantes,
                ROUND(
                    SUM(CASE WHEN descartado IS TRUE THEN 1.0 ELSE 0 END)
                    / COUNT(*) * 100,
                    1
                ) AS pct_descartado
            ')
            ->groupBy('keyword')
            ->havingRaw('COUNT(*) >= ?', [$minSample])
            ->orderByRaw('pct_descartado DESC')
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row) => KeywordAnalisisDTO::fromArray([
            'keyword'       => $row->keyword,
            'total'         => (int) $row->total,
            'descartados'   => (int) $row->descartados,
            'relevantes'    => (int) $row->relevantes,
            'pct_descartado' => (float) $row->pct_descartado,
        ]));
    }

    /**
     * REQ-3: Rank sitios by % descartado, JOIN sitios_web for nombre.
     */
    private function computeTopSitios(int $dias, int $minSample, int $limit): Collection
    {
        $since = Carbon::now()->subDays($dias);

        $rows = DB::table('resultados_scraping AS rs')
            ->leftJoin('sitios_web AS sw', 'rs.sitio_id', '=', 'sw.id')
            ->where('rs.fecha_encontrado', '>=', $since)
            ->selectRaw('
                rs.sitio_id,
                COALESCE(sw.nombre, CAST(rs.sitio_id AS TEXT)) AS sitio_nombre,
                COUNT(*) AS total,
                SUM(CASE WHEN rs.descartado IS TRUE THEN 1 ELSE 0 END) AS descartados,
                ROUND(
                    SUM(CASE WHEN rs.descartado IS TRUE THEN 1.0 ELSE 0 END)
                    / COUNT(*) * 100,
                    1
                ) AS pct_descartado
            ')
            ->groupBy('rs.sitio_id', 'sw.nombre')
            ->havingRaw('COUNT(*) >= ?', [$minSample])
            ->orderByRaw('pct_descartado DESC')
            ->limit($limit)
            ->get();

        return $rows->map(fn (object $row) => SitioAnalisisDTO::fromArray([
            'sitio_id'      => (int) $row->sitio_id,
            'sitio_nombre'  => (string) $row->sitio_nombre,
            'total'         => (int) $row->total,
            'descartados'   => (int) $row->descartados,
            'pct_descartado' => (float) $row->pct_descartado,
        ]));
    }

    /**
     * REQ-4: Compute drift per keyword between current and previous window.
     *
     * Uses a CTE-based approach for readability. Raw SQL is intentional here —
     * Eloquent has no native CTE builder, and the alternative (two separate
     * Eloquent queries + PHP merging) would obscure the intent and add complexity.
     * Both SQLite and PostgreSQL support CTEs (SQLite 3.35+, pgsql always).
     *
     * pctAnterior = null when keyword has no rows in the previous window (N/D).
     */
    private function computeDrift(int $ventanaRecent, int $ventanaPrevious, int $minSample): Collection
    {
        $recentStart   = Carbon::now()->subDays($ventanaRecent);
        $previousStart = Carbon::now()->subDays($ventanaRecent + $ventanaPrevious);
        $previousEnd   = Carbon::now()->subDays($ventanaRecent);

        $recentStartStr   = $recentStart->toDateTimeString();
        $previousStartStr = $previousStart->toDateTimeString();
        $previousEndStr   = $previousEnd->toDateTimeString();

        /** @var array<object> $rows */
        $rows = DB::select(<<<SQL
            WITH current_window AS (
                SELECT
                    keyword,
                    COUNT(*) AS total,
                    SUM(CASE WHEN descartado IS TRUE THEN 1 ELSE 0 END) AS descartados
                FROM resultados_scraping
                WHERE fecha_encontrado >= :recent_start
                GROUP BY keyword
                HAVING COUNT(*) >= :min_sample
            ),
            previous_window AS (
                SELECT
                    keyword,
                    COUNT(*) AS total,
                    SUM(CASE WHEN descartado IS TRUE THEN 1 ELSE 0 END) AS descartados
                FROM resultados_scraping
                WHERE fecha_encontrado >= :prev_start
                  AND fecha_encontrado <  :prev_end
                GROUP BY keyword
            )
            SELECT
                c.keyword,
                ROUND(CAST(c.descartados AS REAL) / c.total * 100, 1)  AS pct_actual,
                ROUND(CAST(p.descartados AS REAL) / p.total * 100, 1)  AS pct_anterior,
                ROUND(
                    CAST(c.descartados AS REAL) / c.total * 100
                    - CASE WHEN p.total IS NOT NULL
                           THEN CAST(p.descartados AS REAL) / p.total * 100
                           ELSE NULL
                      END,
                    1
                ) AS drift_ppt
            FROM current_window c
            LEFT JOIN previous_window p ON c.keyword = p.keyword
            ORDER BY c.keyword
        SQL, [
            'recent_start' => $recentStartStr,
            'min_sample'   => $minSample,
            'prev_start'   => $previousStartStr,
            'prev_end'     => $previousEndStr,
        ]);

        return collect($rows)->map(fn (object $row) => DriftDTO::fromArray([
            'keyword'     => $row->keyword,
            'pct_actual'  => isset($row->pct_actual) ? (float) $row->pct_actual : null,
            'pct_anterior' => isset($row->pct_anterior) && $row->pct_anterior !== null ? (float) $row->pct_anterior : null,
            'drift_ppt'   => isset($row->drift_ppt) && $row->drift_ppt !== null ? (float) $row->drift_ppt : null,
        ]));
    }

    /**
     * REQ-5: Bucket rows by gemini_confianza and report discard rate per bucket.
     *
     * Frozen buckets: 0-49, 50-69, 70-84, 85-100 (per tasks decision).
     * Returns all 4 buckets that have data; empty buckets are omitted per SCN-5.3.
     */
    private function computeConfianza(int $dias): Collection
    {
        $since = Carbon::now()->subDays($dias);

        $rows = DB::table('resultados_scraping')
            ->where('fecha_encontrado', '>=', $since)
            ->whereNotNull('gemini_confianza')
            ->selectRaw("
                CASE
                    WHEN gemini_confianza BETWEEN 85 AND 100 THEN '85-100'
                    WHEN gemini_confianza BETWEEN 70 AND 84  THEN '70-84'
                    WHEN gemini_confianza BETWEEN 50 AND 69  THEN '50-69'
                    ELSE '0-49'
                END AS bucket,
                COUNT(*) AS total,
                SUM(CASE WHEN descartado IS TRUE THEN 1 ELSE 0 END) AS descartados,
                ROUND(
                    SUM(CASE WHEN descartado IS TRUE THEN 1.0 ELSE 0 END)
                    / COUNT(*) * 100,
                    1
                ) AS pct_descartado
            ")
            ->groupByRaw("
                CASE
                    WHEN gemini_confianza BETWEEN 85 AND 100 THEN '85-100'
                    WHEN gemini_confianza BETWEEN 70 AND 84  THEN '70-84'
                    WHEN gemini_confianza BETWEEN 50 AND 69  THEN '50-69'
                    ELSE '0-49'
                END
            ")
            ->orderByRaw("
                CASE
                    WHEN gemini_confianza BETWEEN 85 AND 100 THEN 1
                    WHEN gemini_confianza BETWEEN 70 AND 84  THEN 2
                    WHEN gemini_confianza BETWEEN 50 AND 69  THEN 3
                    ELSE 4
                END
            ")
            ->get();

        return $rows->map(fn (object $row) => ConfianzaBucketDTO::fromArray([
            'bucket'         => $row->bucket,
            'total'          => (int) $row->total,
            'descartados'    => (int) $row->descartados,
            'pct_descartado' => (float) $row->pct_descartado,
        ]));
    }
}
