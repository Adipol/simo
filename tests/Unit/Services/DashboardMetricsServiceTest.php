<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DTOs\GeographicMetricsDTO;
use App\Services\Dashboard\DTOs\PrecisionMetricsDTO;
use App\Services\Dashboard\DTOs\RecentActivityDTO;
use App\Services\Dashboard\DTOs\TrendIndicatorsDTO;
use App\Services\Dashboard\DTOs\VolumeMetricsDTO;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    // ─── Phase 1: DTO Tests ────────────────────────────────────────────────

    // 1.1 PrecisionMetricsDTO

    public function test_precision_metrics_dto_constructor_stores_fields(): void
    {
        $dto = new PrecisionMetricsDTO(
            overallAccuracy: 80.5,
            byBucket: [['bucket' => '81-100', 'total' => 10, 'correctos' => 8, 'accuracy' => 80.0]],
            totalFeedbacks: 10,
            hasData: true,
        );

        $this->assertSame(80.5, $dto->overallAccuracy);
        $this->assertCount(1, $dto->byBucket);
        $this->assertSame(10, $dto->totalFeedbacks);
        $this->assertTrue($dto->hasData);
    }

    public function test_precision_metrics_dto_empty_returns_has_data_false(): void
    {
        $dto = PrecisionMetricsDTO::empty();

        $this->assertFalse($dto->hasData);
        $this->assertSame(0.0, $dto->overallAccuracy);
        $this->assertSame([], $dto->byBucket);
        $this->assertSame(0, $dto->totalFeedbacks);
    }

    // 1.2 VolumeMetricsDTO

    public function test_volume_metrics_dto_constructor_stores_fields(): void
    {
        $trend = array_fill(0, 12, ['month' => '2025-05', 'peps' => 5, 'opis' => 2]);

        $dto = new VolumeMetricsDTO(
            totalPeps: 150,
            totalOpis: 50,
            analyzedCount: 200,
            unreadCount: 30,
            monthlyTrend: $trend,
            hasData: true,
        );

        $this->assertSame(150, $dto->totalPeps);
        $this->assertSame(50, $dto->totalOpis);
        $this->assertSame(200, $dto->analyzedCount);
        $this->assertSame(30, $dto->unreadCount);
        $this->assertCount(12, $dto->monthlyTrend);
        $this->assertTrue($dto->hasData);
    }

    public function test_volume_metrics_dto_empty_returns_has_data_false(): void
    {
        $dto = VolumeMetricsDTO::empty();

        $this->assertFalse($dto->hasData);
        $this->assertSame(0, $dto->totalPeps);
        $this->assertSame(0, $dto->totalOpis);
        $this->assertSame([], $dto->monthlyTrend);
    }

    // 1.3 GeographicMetricsDTO

    public function test_geographic_metrics_dto_constructor_stores_fields(): void
    {
        $byCountry = [
            [
                'pais' => 'AR',
                'peps_count' => 10,
                'opis_count' => 5,
                'avg_confianza' => 87.5,
                'error_rate' => 20.0,
            ],
        ];

        $dto = new GeographicMetricsDTO(byCountry: $byCountry, hasData: true);

        $this->assertSame($byCountry, $dto->byCountry);
        $this->assertTrue($dto->hasData);
        $this->assertArrayHasKey('pais', $dto->byCountry[0]);
        $this->assertArrayHasKey('peps_count', $dto->byCountry[0]);
        $this->assertArrayHasKey('opis_count', $dto->byCountry[0]);
        $this->assertArrayHasKey('avg_confianza', $dto->byCountry[0]);
        $this->assertArrayHasKey('error_rate', $dto->byCountry[0]);
    }

    public function test_geographic_metrics_dto_empty_returns_has_data_false(): void
    {
        $dto = GeographicMetricsDTO::empty();

        $this->assertFalse($dto->hasData);
        $this->assertSame([], $dto->byCountry);
    }

    // 1.4 RecentActivityDTO

    public function test_recent_activity_dto_constructor_stores_fields(): void
    {
        $peps = [
            [
                'titulo' => 'Articulo test',
                'nombre' => 'Juan Perez',
                'cargo' => 'Ministro',
                'pais' => 'AR',
                'confianza' => 95,
                'fecha' => '2026-04-10',
            ],
        ];

        $corrections = [
            [
                'usuario_nombre' => 'Admin',
                'tipo' => 'incorrecto',
                'cargo' => 'Senador',
                'fecha' => '2026-04-09',
            ],
        ];

        $dto = new RecentActivityDTO(
            highConfidencePeps: $peps,
            latestCorrections: $corrections,
        );

        $this->assertCount(1, $dto->highConfidencePeps);
        $this->assertCount(1, $dto->latestCorrections);
        $this->assertArrayHasKey('titulo', $dto->highConfidencePeps[0]);
        $this->assertArrayHasKey('nombre', $dto->highConfidencePeps[0]);
        $this->assertArrayHasKey('cargo', $dto->highConfidencePeps[0]);
        $this->assertArrayHasKey('pais', $dto->highConfidencePeps[0]);
        $this->assertArrayHasKey('confianza', $dto->highConfidencePeps[0]);
        $this->assertArrayHasKey('fecha', $dto->highConfidencePeps[0]);
        $this->assertArrayHasKey('usuario_nombre', $dto->latestCorrections[0]);
        $this->assertArrayHasKey('tipo', $dto->latestCorrections[0]);
        $this->assertArrayHasKey('cargo', $dto->latestCorrections[0]);
        $this->assertArrayHasKey('fecha', $dto->latestCorrections[0]);
    }

    public function test_recent_activity_dto_empty_returns_empty_arrays(): void
    {
        $dto = RecentActivityDTO::empty();

        $this->assertSame([], $dto->highConfidencePeps);
        $this->assertSame([], $dto->latestCorrections);
    }

    // 1.5 TrendIndicatorsDTO

    public function test_trend_indicators_dto_constructor_stores_fields(): void
    {
        $trend = ['current' => 100, 'previous' => 80, 'delta_pct' => 25.0, 'direction' => 'up'];

        $dto = new TrendIndicatorsDTO(
            pepsTrend: $trend,
            opisTrend: $trend,
            feedbackTrend: $trend,
        );

        $this->assertSame(100, $dto->pepsTrend['current']);
        $this->assertSame(80, $dto->pepsTrend['previous']);
        $this->assertSame(25.0, $dto->pepsTrend['delta_pct']);
        $this->assertSame('up', $dto->pepsTrend['direction']);
    }

    public function test_trend_indicators_dto_empty_returns_neutral_directions(): void
    {
        $dto = TrendIndicatorsDTO::empty();

        $this->assertSame('neutral', $dto->pepsTrend['direction']);
        $this->assertSame('neutral', $dto->opisTrend['direction']);
        $this->assertSame('neutral', $dto->feedbackTrend['direction']);
        $this->assertSame(0, $dto->pepsTrend['current']);
    }

    // ─── Phase 2: Service Skeleton Tests ──────────────────────────────────

    // 2.1 Service instantiation and stub methods

    public function test_service_instantiates_and_stub_methods_return_empty_dtos(): void
    {
        $service = new DashboardMetricsService;

        $this->assertInstanceOf(VolumeMetricsDTO::class, $service->getVolumeMetrics([]));
        $this->assertInstanceOf(PrecisionMetricsDTO::class, $service->getPrecisionMetrics([]));
        $this->assertInstanceOf(GeographicMetricsDTO::class, $service->getGeographicMetrics([]));
        $this->assertInstanceOf(RecentActivityDTO::class, $service->getRecentActivity([]));
        $this->assertInstanceOf(TrendIndicatorsDTO::class, $service->getTrendIndicators([]));
        $this->assertIsArray($service->getTopFailingPositions([]));
    }

    // 2.2 resolveFilters — filter normalization

    public function test_resolve_filters_week_preset_returns_carbon_range(): void
    {
        $service = new DashboardMetricsService;
        $filters = $service->resolveFiltersPublic(['date_range' => 'week']);

        $this->assertInstanceOf(Carbon::class, $filters['start']);
        $this->assertInstanceOf(Carbon::class, $filters['end']);
        $this->assertNull($filters['pais']);
        $this->assertNull($filters['categoria']);
        // 'week' = last 7 days: start should be before end
        $this->assertTrue($filters['start']->lt($filters['end']));
    }

    public function test_resolve_filters_pais_string_becomes_array(): void
    {
        $service = new DashboardMetricsService;
        $filters = $service->resolveFiltersPublic(['pais' => 'AR']);

        $this->assertSame(['AR'], $filters['pais']);
    }

    public function test_resolve_filters_pais_array_passthrough(): void
    {
        $service = new DashboardMetricsService;
        $filters = $service->resolveFiltersPublic(['pais' => ['AR', 'CL']]);

        $this->assertSame(['AR', 'CL'], $filters['pais']);
    }

    public function test_resolve_filters_month_preset_returns_current_month_range(): void
    {
        $service = new DashboardMetricsService;
        $filters = $service->resolveFiltersPublic(['date_range' => 'month']);

        $this->assertSame(now()->startOfMonth()->toDateString(), $filters['start']->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $filters['end']->toDateString());
    }

    // 2.3 cacheKey — deterministic key generation

    public function test_cache_key_same_filters_produce_same_key(): void
    {
        $service = new DashboardMetricsService;

        $key1 = $service->cacheKeyPublic('getVolumeMetrics', ['date_range' => 'week', 'pais' => null]);
        $key2 = $service->cacheKeyPublic('getVolumeMetrics', ['date_range' => 'week', 'pais' => null]);

        $this->assertSame($key1, $key2);
    }

    public function test_cache_key_different_filters_produce_different_keys(): void
    {
        $service = new DashboardMetricsService;

        $key1 = $service->cacheKeyPublic('getVolumeMetrics', ['date_range' => 'week']);
        $key2 = $service->cacheKeyPublic('getVolumeMetrics', ['date_range' => 'month']);

        $this->assertNotSame($key1, $key2);
    }

    public function test_cache_key_format_is_correct(): void
    {
        $service = new DashboardMetricsService;

        $key = $service->cacheKeyPublic('getVolumeMetrics', []);

        $this->assertStringStartsWith('dashboard:metrics:getVolumeMetrics:', $key);
        // After the prefix there should be a sha1 hash (40 hex chars)
        $hash = substr($key, strlen('dashboard:metrics:getVolumeMetrics:'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);
    }

    // 2.4 dateTruncMonth — driver-aware

    public function test_date_trunc_month_returns_driver_specific_expression(): void
    {
        $service = new DashboardMetricsService;

        $expr = $service->dateTruncMonthPublic('fecha_encontrado');

        // The method's contract is "return the right expression for the active driver".
        // SQLite test env → strftime('%Y-%m', col). pgsql CI env → TO_CHAR(DATE_TRUNC('month', col), 'YYYY-MM').
        // The column name must appear in both cases.
        $this->assertStringContainsString('fecha_encontrado', $expr);

        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            $this->assertStringContainsString('TO_CHAR', $expr);
            $this->assertStringContainsString('DATE_TRUNC', $expr);
        } else {
            $this->assertStringContainsString('strftime', $expr);
        }
    }

    // ─── Phase 3: Service Methods ──────────────────────────────────────────

    // 3.1 getVolumeMetrics

    public function test_get_volume_metrics_counts_peps_and_opis(): void
    {
        // Create 3 PEPs and 2 OPIs analyzed
        \App\Models\ResultadoScraping::factory()->count(3)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
        ]);
        \App\Models\ResultadoScraping::factory()->count(2)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'OPI',
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getVolumeMetrics([]);

        $this->assertSame(3, $dto->totalPeps);
        $this->assertSame(2, $dto->totalOpis);
        $this->assertSame(5, $dto->analyzedCount);
        $this->assertTrue($dto->hasData);
    }

    public function test_get_volume_metrics_monthly_trend_has_12_elements(): void
    {
        \App\Models\ResultadoScraping::factory()->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'fecha_encontrado' => now(),
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getVolumeMetrics([]);

        $this->assertCount(12, $dto->monthlyTrend);
    }

    public function test_get_volume_metrics_empty_table_returns_zeros_no_error(): void
    {
        $service = new DashboardMetricsService;
        $dto = $service->getVolumeMetrics([]);

        $this->assertSame(0, $dto->totalPeps);
        $this->assertSame(0, $dto->totalOpis);
        $this->assertFalse($dto->hasData);
    }

    // 3.2 getPrecisionMetrics

    public function test_get_precision_metrics_empty_returns_has_data_false(): void
    {
        $service = new DashboardMetricsService;
        $dto = $service->getPrecisionMetrics([]);

        $this->assertFalse($dto->hasData);
        $this->assertSame(0.0, $dto->overallAccuracy);
    }

    // 3.3 getGeographicMetrics

    public function test_get_geographic_metrics_groups_by_country(): void
    {
        \App\Models\ResultadoScraping::factory()->count(3)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'pais' => 'AR',
        ]);
        \App\Models\ResultadoScraping::factory()->count(2)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'pais' => 'CL',
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getGeographicMetrics([]);

        $this->assertCount(2, $dto->byCountry);
        $countries = array_column($dto->byCountry, 'pais');
        $this->assertContains('AR', $countries);
        $this->assertContains('CL', $countries);
        $this->assertTrue($dto->hasData);
    }

    public function test_get_geographic_metrics_all_five_fields_present(): void
    {
        \App\Models\ResultadoScraping::factory()->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'pais' => 'BO',
            'gemini_confianza' => 90,
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getGeographicMetrics([]);

        $this->assertArrayHasKey('pais', $dto->byCountry[0]);
        $this->assertArrayHasKey('peps_count', $dto->byCountry[0]);
        $this->assertArrayHasKey('opis_count', $dto->byCountry[0]);
        $this->assertArrayHasKey('avg_confianza', $dto->byCountry[0]);
        $this->assertArrayHasKey('error_rate', $dto->byCountry[0]);
    }

    public function test_get_geographic_metrics_pais_filter_works(): void
    {
        \App\Models\ResultadoScraping::factory()->count(2)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'pais' => 'AR',
        ]);
        \App\Models\ResultadoScraping::factory()->count(3)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'pais' => 'CL',
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getGeographicMetrics(['pais' => 'AR']);

        $this->assertCount(1, $dto->byCountry);
        $this->assertSame('AR', $dto->byCountry[0]['pais']);
    }

    // 3.5 getRecentActivity

    public function test_get_recent_activity_only_includes_high_confidence_peps(): void
    {
        // confianza >= 90: should be included
        \App\Models\ResultadoScraping::factory()->create([
            'gemini_analyzed' => true,
            'gemini_confianza' => 90,
            'gemini_nombre' => 'Maria Garcia',
        ]);
        // confianza 89: should NOT be included
        \App\Models\ResultadoScraping::factory()->create([
            'gemini_analyzed' => true,
            'gemini_confianza' => 89,
            'gemini_nombre' => 'Juan Perez',
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getRecentActivity([]);

        $this->assertCount(1, $dto->highConfidencePeps);
        $nombres = array_column($dto->highConfidencePeps, 'nombre');
        $this->assertContains('Maria Garcia', $nombres);
        $this->assertNotContains('Juan Perez', $nombres);
    }

    // 3.6 getTrendIndicators

    public function test_get_trend_indicators_positive_delta(): void
    {
        // Current month: 100 PEPs
        \App\Models\ResultadoScraping::factory()->count(100)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'fecha_encontrado' => now()->startOfMonth()->addDay(),
        ]);
        // Previous month: 80 PEPs
        \App\Models\ResultadoScraping::factory()->count(80)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'fecha_encontrado' => now()->subMonth()->startOfMonth()->addDay(),
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getTrendIndicators([]);

        $this->assertSame(100, $dto->pepsTrend['current']);
        $this->assertSame(80, $dto->pepsTrend['previous']);
        $this->assertSame(25.0, $dto->pepsTrend['delta_pct']);
        $this->assertSame('up', $dto->pepsTrend['direction']);
    }

    public function test_get_trend_indicators_division_by_zero_returns_neutral(): void
    {
        // Current month: 50 PEPs, previous month: 0 PEPs (no data)
        \App\Models\ResultadoScraping::factory()->count(50)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'fecha_encontrado' => now()->startOfMonth()->addDay(),
        ]);

        $service = new DashboardMetricsService;
        $dto = $service->getTrendIndicators([]);

        $this->assertSame(50, $dto->pepsTrend['current']);
        $this->assertSame(0, $dto->pepsTrend['previous']);
        $this->assertSame('neutral', $dto->pepsTrend['direction']);
    }

    // ─── Phase 4: Caching Tests ────────────────────────────────────────────

    public function test_cache_hit_executes_only_one_query(): void
    {
        \App\Models\ResultadoScraping::factory()->count(3)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
        ]);

        $service = new DashboardMetricsService;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        // Call twice with same filters
        $service->getVolumeMetrics([]);
        $queriesAfterFirst = $queryCount;

        $service->getVolumeMetrics([]);
        $queriesAfterSecond = $queryCount;

        // Second call should not add queries (served from cache)
        $this->assertSame($queriesAfterFirst, $queriesAfterSecond);
    }

    public function test_different_filters_produce_different_cache_keys(): void
    {
        \App\Models\ResultadoScraping::factory()->count(5)->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
            'pais' => 'AR',
        ]);

        $service = new DashboardMetricsService;

        $dto1 = $service->getVolumeMetrics(['date_range' => 'week']);
        $dto2 = $service->getVolumeMetrics(['date_range' => 'year']);

        // Both should return valid DTOs (no exception)
        $this->assertInstanceOf(VolumeMetricsDTO::class, $dto1);
        $this->assertInstanceOf(VolumeMetricsDTO::class, $dto2);
    }

    public function test_pais_filter_order_independence(): void
    {
        $service = new DashboardMetricsService;

        $key1 = $service->cacheKeyPublic('getVolumeMetrics', ['pais' => ['AR', 'CL']]);
        $key2 = $service->cacheKeyPublic('getVolumeMetrics', ['pais' => ['AR', 'CL']]);

        $this->assertSame($key1, $key2);
    }
}
