<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Services\Dashboard\DTOs\GeographicMetricsDTO;
use App\Services\Dashboard\DTOs\PrecisionMetricsDTO;
use App\Services\Dashboard\DTOs\RecentActivityDTO;
use App\Services\Dashboard\DTOs\TrendIndicatorsDTO;
use App\Services\Dashboard\DTOs\VolumeMetricsDTO;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    private readonly int $cacheTtlSeconds;

    public function __construct(int $cacheTtlSeconds = 300)
    {
        $this->cacheTtlSeconds = $cacheTtlSeconds;
    }

    // ─── Public API ────────────────────────────────────────────────────────

    public function getVolumeMetrics(array $filters = []): VolumeMetricsDTO
    {
        return Cache::remember(
            $this->cacheKey('getVolumeMetrics', $filters),
            $this->cacheTtlSeconds,
            fn () => $this->computeVolumeMetrics($filters)
        );
    }

    public function getPrecisionMetrics(array $filters = []): PrecisionMetricsDTO
    {
        return Cache::remember(
            $this->cacheKey('getPrecisionMetrics', $filters),
            $this->cacheTtlSeconds,
            fn () => $this->computePrecisionMetrics($filters)
        );
    }

    public function getGeographicMetrics(array $filters = []): GeographicMetricsDTO
    {
        return Cache::remember(
            $this->cacheKey('getGeographicMetrics', $filters),
            $this->cacheTtlSeconds,
            fn () => $this->computeGeographicMetrics($filters)
        );
    }

    public function getRecentActivity(array $filters = []): RecentActivityDTO
    {
        return Cache::remember(
            $this->cacheKey('getRecentActivity', $filters),
            $this->cacheTtlSeconds,
            fn () => $this->computeRecentActivity($filters)
        );
    }

    public function getTrendIndicators(array $filters = []): TrendIndicatorsDTO
    {
        return Cache::remember(
            $this->cacheKey('getTrendIndicators', $filters),
            $this->cacheTtlSeconds,
            fn () => $this->computeTrendIndicators($filters)
        );
    }

    public function getTopFailingPositions(array $filters = [], int $minSamples = 3): array
    {
        return Cache::remember(
            $this->cacheKey('getTopFailingPositions', array_merge($filters, ['minSamples' => $minSamples])),
            $this->cacheTtlSeconds,
            fn () => $this->computeTopFailingPositions($filters, $minSamples)
        );
    }

    // ─── Test-accessible public wrappers (only used in tests) ─────────────

    public function resolveFiltersPublic(array $filters): array
    {
        return $this->resolveFilters($filters);
    }

    public function cacheKeyPublic(string $method, array $filters): string
    {
        return $this->cacheKey($method, $filters);
    }

    public function dateTruncMonthPublic(string $col): string
    {
        return $this->dateTruncMonth($col);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────

    private function resolveFilters(array $filters): array
    {
        // Compute date range from preset
        $dateRange = $filters['date_range'] ?? null;
        $start = null;
        $end = null;

        if ($dateRange !== null) {
            $now = Carbon::now();

            [$start, $end] = match ($dateRange) {
                'today' => [Carbon::today(), Carbon::today()->endOfDay()],
                'week' => [Carbon::now()->subDays(6)->startOfDay(), $now->endOfDay()],
                'month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
                'quarter' => [Carbon::now()->startOfQuarter(), Carbon::now()->endOfQuarter()],
                'year' => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
                default => [null, null],
            };
        }

        // Normalize pais: null stays null, string becomes array, array passthrough
        $pais = $filters['pais'] ?? null;
        if (is_string($pais)) {
            $pais = [$pais];
        }

        return [
            'start' => $start,
            'end' => $end,
            'pais' => $pais,
            'categoria' => $filters['categoria'] ?? null,
        ];
    }

    private function cacheKey(string $method, array $filters): string
    {
        $normalized = $this->resolveFilters($filters);

        // Serialize for cache key: convert Carbon to strings
        $serializable = [
            'start' => $normalized['start'] instanceof Carbon ? $normalized['start']->toISOString() : null,
            'end' => $normalized['end'] instanceof Carbon ? $normalized['end']->toISOString() : null,
            'pais' => $normalized['pais'],
            'categoria' => $normalized['categoria'],
        ];

        ksort($serializable);

        return 'dashboard:metrics:'.$method.':'.sha1(json_encode($serializable));
    }

    private function dateTruncMonth(string $col): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "TO_CHAR(DATE_TRUNC('month', {$col}), 'YYYY-MM')",
            default => "strftime('%Y-%m', {$col})",
        };
    }

    // ─── Compute Methods ──────────────────────────────────────────────────

    private function computeVolumeMetrics(array $filters): VolumeMetricsDTO
    {
        $resolved = $this->resolveFilters($filters);

        $query = DB::table('resultados_scraping')
            ->where('gemini_analyzed', true);

        $this->applyDateFilter($query, $resolved);
        $this->applyPaisFilter($query, $resolved);

        if ($resolved['categoria'] !== null) {
            $query->where('gemini_categoria', $resolved['categoria']);
        }

        $totals = (clone $query)
            ->selectRaw('
                SUM(CASE WHEN gemini_categoria = \'PEP\' THEN 1 ELSE 0 END) AS total_peps,
                SUM(CASE WHEN gemini_categoria = \'OPI\' THEN 1 ELSE 0 END) AS total_opis,
                COUNT(*) AS analyzed_count
            ')
            ->first();

        $unreadCount = DB::table('resultados_scraping')
            ->where('leido', false)
            ->count();

        $totalPeps = (int) ($totals->total_peps ?? 0);
        $totalOpis = (int) ($totals->total_opis ?? 0);
        $analyzedCount = (int) ($totals->analyzed_count ?? 0);

        // Build 12-month trend
        $monthlyTrend = $this->computeMonthlyTrend($resolved);

        return new VolumeMetricsDTO(
            totalPeps: $totalPeps,
            totalOpis: $totalOpis,
            analyzedCount: $analyzedCount,
            unreadCount: $unreadCount,
            monthlyTrend: $monthlyTrend,
            hasData: $analyzedCount > 0,
        );
    }

    private function computeMonthlyTrend(array $resolved): array
    {
        $monthExpr = $this->dateTruncMonth('fecha_encontrado');

        $rows = DB::table('resultados_scraping')
            ->where('gemini_analyzed', true)
            ->selectRaw("
                {$monthExpr} AS month,
                SUM(CASE WHEN gemini_categoria = 'PEP' THEN 1 ELSE 0 END) AS peps,
                SUM(CASE WHEN gemini_categoria = 'OPI' THEN 1 ELSE 0 END) AS opis
            ")
            ->where('fecha_encontrado', '>=', Carbon::now()->subMonths(11)->startOfMonth())
            ->groupByRaw($monthExpr)
            ->orderByRaw($monthExpr)
            ->get()
            ->keyBy('month');

        // Build a full 12-month array with zeroes for months with no data
        $trend = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthKey = Carbon::now()->subMonths($i)->format('Y-m');
            $row = $rows->get($monthKey);
            $trend[] = [
                'month' => $monthKey,
                'peps' => (int) ($row?->peps ?? 0),
                'opis' => (int) ($row?->opis ?? 0),
            ];
        }

        return $trend;
    }

    private function computePrecisionMetrics(array $filters): PrecisionMetricsDTO
    {
        $resolved = $this->resolveFilters($filters);

        $query = DB::table('resultados_scraping AS rs')
            ->join('clasificaciones_feedback AS fb', 'rs.id', '=', 'fb.resultado_scraping_id')
            ->where('rs.gemini_analyzed', true);

        $this->applyDateFilter($query, $resolved, 'rs.fecha_encontrado');
        $this->applyPaisFilter($query, $resolved, 'rs');

        $rows = (clone $query)
            ->selectRaw("
                CASE
                    WHEN rs.gemini_confianza BETWEEN 0 AND 50 THEN '0-50'
                    WHEN rs.gemini_confianza BETWEEN 51 AND 80 THEN '51-80'
                    ELSE '81-100'
                END AS bucket,
                COUNT(*) AS total,
                SUM(CASE WHEN fb.tipo = 'correcto' THEN 1 ELSE 0 END) AS correctos
            ")
            ->groupByRaw("
                CASE
                    WHEN rs.gemini_confianza BETWEEN 0 AND 50 THEN '0-50'
                    WHEN rs.gemini_confianza BETWEEN 51 AND 80 THEN '51-80'
                    ELSE '81-100'
                END
            ")
            ->get();

        if ($rows->isEmpty()) {
            return PrecisionMetricsDTO::empty();
        }

        $totalFeedbacks = 0;
        $totalCorrectos = 0;
        $byBucket = [];

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $correctos = (int) $row->correctos;
            $accuracy = $total > 0 ? round(($correctos / $total) * 100, 1) : 0.0;

            $byBucket[] = [
                'bucket' => $row->bucket,
                'total' => $total,
                'correctos' => $correctos,
                'accuracy' => $accuracy,
            ];

            $totalFeedbacks += $total;
            $totalCorrectos += $correctos;
        }

        $overallAccuracy = $totalFeedbacks > 0
            ? round(($totalCorrectos / $totalFeedbacks) * 100, 1)
            : 0.0;

        return new PrecisionMetricsDTO(
            overallAccuracy: $overallAccuracy,
            byBucket: $byBucket,
            totalFeedbacks: $totalFeedbacks,
            hasData: true,
        );
    }

    private function computeGeographicMetrics(array $filters): GeographicMetricsDTO
    {
        $resolved = $this->resolveFilters($filters);

        $query = DB::table('resultados_scraping AS rs')
            ->where('rs.gemini_analyzed', true);

        $this->applyDateFilter($query, $resolved, 'rs.fecha_encontrado');
        $this->applyPaisFilter($query, $resolved, 'rs');

        // Join feedback for error_rate
        $rows = (clone $query)
            ->selectRaw("
                rs.pais,
                SUM(CASE WHEN rs.gemini_categoria = 'PEP' THEN 1 ELSE 0 END) AS peps_count,
                SUM(CASE WHEN rs.gemini_categoria = 'OPI' THEN 1 ELSE 0 END) AS opis_count,
                AVG(rs.gemini_confianza) AS avg_confianza,
                COUNT(fb.id) AS total_feedback,
                SUM(CASE WHEN fb.tipo = 'incorrecto' THEN 1 ELSE 0 END) AS total_incorrecto
            ")
            ->leftJoin('clasificaciones_feedback AS fb', 'rs.id', '=', 'fb.resultado_scraping_id')
            ->groupBy('rs.pais')
            ->orderByRaw('peps_count DESC')
            ->get();

        if ($rows->isEmpty()) {
            return GeographicMetricsDTO::empty();
        }

        $byCountry = [];
        foreach ($rows as $row) {
            $totalFb = (int) ($row->total_feedback ?? 0);
            $totalInc = (int) ($row->total_incorrecto ?? 0);
            $errorRate = $totalFb > 0 ? round(($totalInc / $totalFb) * 100, 1) : 0.0;

            $byCountry[] = [
                'pais' => $row->pais,
                'peps_count' => (int) $row->peps_count,
                'opis_count' => (int) $row->opis_count,
                'avg_confianza' => round((float) ($row->avg_confianza ?? 0), 1),
                'error_rate' => $errorRate,
            ];
        }

        return new GeographicMetricsDTO(byCountry: $byCountry, hasData: true);
    }

    private function computeRecentActivity(array $filters): RecentActivityDTO
    {
        $resolved = $this->resolveFilters($filters);

        // High-confidence PEPs (confianza >= 90)
        $pepsQuery = DB::table('resultados_scraping')
            ->where('gemini_analyzed', true)
            ->where('gemini_confianza', '>=', 90)
            ->select([
                'titulo',
                'gemini_nombre AS nombre',
                'gemini_cargo AS cargo',
                'pais',
                'gemini_confianza AS confianza',
                'fecha_encontrado AS fecha',
            ])
            ->orderBy('fecha_encontrado', 'desc')
            ->limit(10);

        $this->applyPaisFilter($pepsQuery, $resolved);

        $peps = $pepsQuery->get()->map(fn ($r) => [
            'titulo' => $r->titulo,
            'nombre' => $r->nombre,
            'cargo' => $r->cargo,
            'pais' => $r->pais,
            'confianza' => (int) $r->confianza,
            'fecha' => $r->fecha,
        ])->toArray();

        // Latest corrections (feedback with usuario)
        $corrections = DB::table('clasificaciones_feedback AS fb')
            ->join('users AS u', 'fb.usuario_id', '=', 'u.id')
            ->select([
                'u.name AS usuario_nombre',
                'fb.tipo',
                'fb.corregido_cargo AS cargo',
                'fb.created_at AS fecha',
            ])
            ->orderBy('fb.created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'usuario_nombre' => $r->usuario_nombre,
                'tipo' => $r->tipo,
                'cargo' => $r->cargo,
                'fecha' => $r->fecha,
            ])
            ->toArray();

        return new RecentActivityDTO(
            highConfidencePeps: $peps,
            latestCorrections: $corrections,
        );
    }

    private function computeTrendIndicators(array $filters): TrendIndicatorsDTO
    {
        // Compare current month vs previous month
        $currentStart = Carbon::now()->startOfMonth();
        $currentEnd = Carbon::now()->endOfMonth();
        $previousStart = Carbon::now()->subMonth()->startOfMonth();
        $previousEnd = Carbon::now()->subMonth()->endOfMonth();

        $currentPeps = $this->countByPeriod('PEP', $currentStart, $currentEnd);
        $previousPeps = $this->countByPeriod('PEP', $previousStart, $previousEnd);

        $currentOpis = $this->countByPeriod('OPI', $currentStart, $currentEnd);
        $previousOpis = $this->countByPeriod('OPI', $previousStart, $previousEnd);

        $currentFeedback = $this->countFeedbackByPeriod($currentStart, $currentEnd);
        $previousFeedback = $this->countFeedbackByPeriod($previousStart, $previousEnd);

        return new TrendIndicatorsDTO(
            pepsTrend: $this->buildTrend($currentPeps, $previousPeps),
            opisTrend: $this->buildTrend($currentOpis, $previousOpis),
            feedbackTrend: $this->buildTrend($currentFeedback, $previousFeedback),
        );
    }

    private function computeTopFailingPositions(array $filters, int $minSamples): array
    {
        $resolved = $this->resolveFilters($filters);

        $query = DB::table('resultados_scraping AS rs')
            ->join('clasificaciones_feedback AS fb', 'rs.id', '=', 'fb.resultado_scraping_id')
            ->where('rs.gemini_analyzed', true)
            ->whereNotNull('rs.gemini_cargo');

        $this->applyDateFilter($query, $resolved, 'rs.fecha_encontrado');
        $this->applyPaisFilter($query, $resolved, 'rs');

        $rows = (clone $query)
            ->selectRaw("
                rs.gemini_cargo AS cargo,
                COUNT(*) AS total_muestras,
                SUM(CASE WHEN fb.tipo = 'incorrecto' THEN 1 ELSE 0 END) AS total_errores
            ")
            ->groupBy('rs.gemini_cargo')
            ->havingRaw('COUNT(*) >= ?', [$minSamples])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $rows
            ->map(function ($row) {
                $total = (int) $row->total_muestras;
                $errores = (int) $row->total_errores;
                $errorRate = $total > 0 ? round(($errores / $total) * 100, 1) : 0.0;

                return [
                    'cargo' => $row->cargo,
                    'total_muestras' => $total,
                    'total_errores' => $errores,
                    'error_rate' => $errorRate,
                ];
            })
            ->sortByDesc('error_rate')
            ->values()
            ->toArray();
    }

    // ─── Query Helpers ────────────────────────────────────────────────────

    private function applyDateFilter(
        \Illuminate\Database\Query\Builder $query,
        array $resolved,
        string $col = 'fecha_encontrado',
    ): void {
        if ($resolved['start'] !== null) {
            $query->where($col, '>=', $resolved['start']);
        }
        if ($resolved['end'] !== null) {
            $query->where($col, '<=', $resolved['end']);
        }
    }

    private function applyPaisFilter(
        \Illuminate\Database\Query\Builder $query,
        array $resolved,
        string $alias = '',
    ): void {
        if ($resolved['pais'] === null) {
            return;
        }

        $col = $alias !== '' ? "{$alias}.pais" : 'pais';
        $query->whereIn($col, $resolved['pais']);
    }

    private function countByPeriod(string $categoria, Carbon $start, Carbon $end): int
    {
        return DB::table('resultados_scraping')
            ->where('gemini_analyzed', true)
            ->where('gemini_categoria', $categoria)
            ->whereBetween('fecha_encontrado', [$start, $end])
            ->count();
    }

    private function countFeedbackByPeriod(Carbon $start, Carbon $end): int
    {
        return DB::table('clasificaciones_feedback')
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    private function buildTrend(int $current, int $previous): array
    {
        if ($previous === 0) {
            $deltaPct = 0.0;
            $direction = 'neutral';
        } else {
            $deltaPct = round((($current - $previous) / $previous) * 100, 1);
            $direction = match (true) {
                $deltaPct > 0 => 'up',
                $deltaPct < 0 => 'down',
                default => 'neutral',
            };
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'delta_pct' => $deltaPct,
            'direction' => $direction,
        ];
    }
}
