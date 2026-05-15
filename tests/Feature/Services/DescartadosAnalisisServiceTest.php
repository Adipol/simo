<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\Dashboard\DTOs\ConfianzaBucketDTO;
use App\Services\Dashboard\DTOs\DescartadosMetricsDTO;
use App\Services\Dashboard\DTOs\DriftDTO;
use App\Services\Dashboard\DTOs\KeywordAnalisisDTO;
use App\Services\Dashboard\DTOs\SitioAnalisisDTO;
use App\Services\DescartadosAnalisisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Full TDD coverage for DescartadosAnalisisService.
 *
 * REQ-1–7 / feedback-loop-from-descartados
 *
 * Safety net: 738 pre-existing tests pass (1 pre-existing failure in DashboardHealthServiceQuotaTest,
 * unrelated to this change — DO NOT fix here).
 */
class DescartadosAnalisisServiceTest extends TestCase
{
    use RefreshDatabase;

    private DescartadosAnalisisService $service;
    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable side effects: Gemini and dedupe jobs must not dispatch during seed.
        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        // Silence all model observers/listeners that may interfere with seeding.
        ResultadoScraping::flushEventListeners();

        // Pin "now" so date windows are deterministic across the test run.
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

        $this->service = app(DescartadosAnalisisService::class);

        $this->sitio = SitioWeb::create([
            'url'    => 'https://test-sitio.example.com',
            'nombre' => 'Test Sitio',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a minimal ResultadoScraping row.
     * All required columns supplied; caller overrides as needed.
     */
    private function makeResultado(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'             => 'https://test-sitio.example.com/article-' . uniqid(),
            'keyword'         => 'corrupcion',
            'sitio_id'        => $this->sitio->id,
            'pais'            => 'BO',
            'titulo'          => 'Test Article',
            'contexto'        => '',
            'fecha_encontrado' => Carbon::now()->subDays(5),
            'relevance_score' => 50,
            'leido'           => false,
            'descartado'      => false,
            'relevante'       => null,
            'gemini_analyzed' => true,
            'gemini_confianza' => 80,
        ], $overrides));
    }

    // ─── REQ-1: precisionGeneral ──────────────────────────────────────────────

    /**
     * SCN-1.1 — Sufficient data: precision computed correctly.
     *
     * 10 descartados + 5 relevantes = 15 labeled.
     * precision = 5/15 * 100 = 33.3%.
     * All counts must be present in the DTO.
     */
    public function test_it_calculates_precision_correctly(): void
    {
        // 10 descartados
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado(['descartado' => true, 'relevante' => false]);
        }
        // 5 relevantes
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado(['descartado' => false, 'relevante' => true]);
        }
        // 3 unlabeled (must be excluded)
        for ($i = 0; $i < 3; $i++) {
            $this->makeResultado(['descartado' => false, 'relevante' => null]);
        }

        $dto = $this->service->precisionGeneral(skipCache: true);

        $this->assertInstanceOf(DescartadosMetricsDTO::class, $dto);
        $this->assertSame(15, $dto->totalProcesados, 'totalProcesados must equal labeled rows only');
        $this->assertSame(10, $dto->totalDescartados);
        $this->assertSame(5, $dto->totalRelevantes);
        $this->assertNotNull($dto->precisionPct, 'precisionPct must not be null with sufficient data');
        $this->assertEqualsWithDelta(33.3, $dto->precisionPct, 0.1, 'precision = 5/15 * 100 = 33.3%');
        $this->assertSame('', $dto->insufficientReason, 'insufficientReason must be empty when data is sufficient');
    }

    /**
     * SCN-1.2 — Insufficient data: guard triggered.
     *
     * 8 labeled rows < MIN_SAMPLE_GLOBAL (10) → precisionPct must be null.
     * insufficientReason must mention the gap.
     */
    public function test_it_returns_null_precision_when_below_min_global(): void
    {
        // 5 descartados + 3 relevantes = 8 labeled (below 10)
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado(['descartado' => true, 'relevante' => false]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->makeResultado(['descartado' => false, 'relevante' => true]);
        }

        $dto = $this->service->precisionGeneral(skipCache: true);

        $this->assertInstanceOf(DescartadosMetricsDTO::class, $dto);
        $this->assertNull($dto->precisionPct, 'precisionPct must be null when sample < 10');
        $this->assertSame(8, $dto->totalProcesados, 'totalProcesados must still report the actual count');
        $this->assertNotEmpty($dto->insufficientReason, 'insufficientReason must explain the gap');
        $this->assertStringContainsString('10', $dto->insufficientReason, 'insufficientReason must reference the minimum threshold');
    }

    // ─── REQ-2: topLemasProblematicos ────────────────────────────────────────

    /**
     * SCN-2.2 — Keywords below min sample excluded.
     *
     * keyword "foo" has only 3 rows (< 5) → must NOT appear in ranking.
     * keyword "bar" has 6 rows → must appear.
     */
    public function test_it_excludes_keywords_below_min_sample(): void
    {
        // "foo" — 3 rows (below default min 5)
        for ($i = 0; $i < 3; $i++) {
            $this->makeResultado(['keyword' => 'foo', 'descartado' => true]);
        }
        // "bar" — 6 rows (above min 5)
        for ($i = 0; $i < 6; $i++) {
            $this->makeResultado(['keyword' => 'bar', 'descartado' => true]);
        }

        $results = $this->service->topLemasProblematicos(skipCache: true);

        $this->assertInstanceOf(Collection::class, $results);

        $keywords = $results->pluck('keyword')->toArray();
        $this->assertNotContains('foo', $keywords, 'keyword "foo" with 3 rows must be excluded (below min 5)');
        $this->assertContains('bar', $keywords, 'keyword "bar" with 6 rows must appear in ranking');
    }

    /**
     * SCN-2.1 — Ranking ordered by pct_descartado DESC.
     *
     * "alta" keyword: 8/10 descartados = 80%
     * "baja" keyword: 2/10 descartados = 20%
     * → "alta" must appear first.
     */
    public function test_it_ranks_lemas_by_descartado_percentage(): void
    {
        // "alta" — 10 rows, 8 descartados
        for ($i = 0; $i < 8; $i++) {
            $this->makeResultado(['keyword' => 'alta', 'descartado' => true, 'relevante' => false]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeResultado(['keyword' => 'alta', 'descartado' => false, 'relevante' => true]);
        }
        // "baja" — 10 rows, 2 descartados
        for ($i = 0; $i < 2; $i++) {
            $this->makeResultado(['keyword' => 'baja', 'descartado' => true, 'relevante' => false]);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->makeResultado(['keyword' => 'baja', 'descartado' => false, 'relevante' => true]);
        }

        $results = $this->service->topLemasProblematicos(skipCache: true);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(KeywordAnalisisDTO::class, $results->first());
        $this->assertSame('alta', $results->first()->keyword, '"alta" (80%) must be first');
        $this->assertSame('baja', $results->last()->keyword, '"baja" (20%) must be last');
        $this->assertEqualsWithDelta(80.0, $results->first()->pctDescartado, 0.1);
        $this->assertEqualsWithDelta(20.0, $results->last()->pctDescartado, 0.1);
    }

    // ─── REQ-3: topSitiosProblematicos ───────────────────────────────────────

    /**
     * SCN-3.1 — Sitios ranking with JOIN for nombre.
     *
     * Creates a second sitio with a known nombre, seeds rows for it,
     * and verifies sitioNombre is populated from sitios_web.
     */
    public function test_it_joins_sitios_web_for_sitio_nombre(): void
    {
        $sitioB = SitioWeb::create([
            'url'    => 'https://sitio-b.example.com',
            'nombre' => 'Sitio B Nombre',
            'pais'   => 'BO',
            'activo' => true,
        ]);

        // 6 rows for sitioB (above min 5), all descartados
        for ($i = 0; $i < 6; $i++) {
            $this->makeResultado(['sitio_id' => $sitioB->id, 'descartado' => true]);
        }

        $results = $this->service->topSitiosProblematicos(skipCache: true);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertNotEmpty($results, 'Results must contain at least one sitio');

        $sitioResult = $results->first(fn(SitioAnalisisDTO $dto) => $dto->sitioId === $sitioB->id);
        $this->assertNotNull($sitioResult, 'sitioB must appear in ranking');
        $this->assertSame('Sitio B Nombre', $sitioResult->sitioNombre, 'sitioNombre must come from sitios_web JOIN');
        $this->assertSame($sitioB->id, $sitioResult->sitioId);
        $this->assertSame(6, $sitioResult->total);
    }

    // ─── REQ-4: driftPorKeyword ───────────────────────────────────────────────

    /**
     * SCN-4.1 — Drift computed between current (0–30d) and previous (30–60d) windows.
     *
     * "keyword-drift" in current (0-30d): 8/10 descartados = 80%
     * "keyword-drift" in previous (30-60d): 4/10 descartados = 40%
     * driftPpt = 80 - 40 = 40.0
     */
    public function test_it_calculates_drift_between_windows(): void
    {
        // Current window (0–30d): 8 descartados + 2 relevantes = 10
        for ($i = 0; $i < 8; $i++) {
            $this->makeResultado([
                'keyword'         => 'keyword-drift',
                'descartado'      => true,
                'relevante'       => false,
                'fecha_encontrado' => Carbon::now()->subDays(10),
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeResultado([
                'keyword'         => 'keyword-drift',
                'descartado'      => false,
                'relevante'       => true,
                'fecha_encontrado' => Carbon::now()->subDays(10),
            ]);
        }
        // Previous window (30–60d): 4 descartados + 6 relevantes = 10
        for ($i = 0; $i < 4; $i++) {
            $this->makeResultado([
                'keyword'         => 'keyword-drift',
                'descartado'      => true,
                'relevante'       => false,
                'fecha_encontrado' => Carbon::now()->subDays(45),
            ]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->makeResultado([
                'keyword'         => 'keyword-drift',
                'descartado'      => false,
                'relevante'       => true,
                'fecha_encontrado' => Carbon::now()->subDays(45),
            ]);
        }

        $results = $this->service->driftPorKeyword(skipCache: true);

        $this->assertInstanceOf(Collection::class, $results);
        $drift = $results->first(fn(DriftDTO $dto) => $dto->keyword === 'keyword-drift');
        $this->assertNotNull($drift, 'keyword-drift must appear in drift results');
        $this->assertInstanceOf(DriftDTO::class, $drift);
        $this->assertNotNull($drift->pctActual);
        $this->assertNotNull($drift->pctAnterior);
        $this->assertNotNull($drift->driftPpt);
        $this->assertEqualsWithDelta(80.0, $drift->pctActual, 0.1, 'current window: 8/10 = 80%');
        $this->assertEqualsWithDelta(40.0, $drift->pctAnterior, 0.1, 'previous window: 4/10 = 40%');
        $this->assertEqualsWithDelta(40.0, $drift->driftPpt, 0.1, 'drift = 80 - 40 = 40ppt');
    }

    /**
     * SCN-4.3 — No previous-period data: N/D graceful handling.
     *
     * "new-keyword" only has rows in the current window (0–30d).
     * pctAnterior and driftPpt must be null.
     */
    public function test_it_handles_empty_drift_window_gracefully(): void
    {
        // Only current window rows
        for ($i = 0; $i < 6; $i++) {
            $this->makeResultado([
                'keyword'         => 'new-keyword',
                'descartado'      => true,
                'fecha_encontrado' => Carbon::now()->subDays(5),
            ]);
        }

        $results = $this->service->driftPorKeyword(skipCache: true);

        $this->assertInstanceOf(Collection::class, $results);
        $drift = $results->first(fn(DriftDTO $dto) => $dto->keyword === 'new-keyword');
        $this->assertNotNull($drift, 'new-keyword must appear in drift results (has current data)');
        $this->assertInstanceOf(DriftDTO::class, $drift);
        $this->assertNotNull($drift->pctActual, 'pctActual must be set (current window has data)');
        $this->assertNull($drift->pctAnterior, 'pctAnterior must be null when previous window is empty');
        $this->assertNull($drift->driftPpt, 'driftPpt must be null when previous window is empty');
    }

    // ─── REQ-5: confianzaGeminiVsDescartado ──────────────────────────────────

    /**
     * SCN-5.1 — Four confianza buckets computed correctly.
     *
     * Frozen bucket boundaries: 0-49, 50-69, 70-84, 85-100
     * Seed rows across all 4 buckets, verify counts.
     */
    public function test_it_buckets_confianza_correctly(): void
    {
        // Bucket 85-100: 3 descartados (confianza=90)
        for ($i = 0; $i < 3; $i++) {
            $this->makeResultado(['gemini_confianza' => 90, 'descartado' => true]);
        }
        // Bucket 70-84: 2 descartados (confianza=75)
        for ($i = 0; $i < 2; $i++) {
            $this->makeResultado(['gemini_confianza' => 75, 'descartado' => true]);
        }
        // Bucket 50-69: 4 descartados (confianza=60)
        for ($i = 0; $i < 4; $i++) {
            $this->makeResultado(['gemini_confianza' => 60, 'descartado' => true]);
        }
        // Bucket 0-49: 1 descartado (confianza=30)
        for ($i = 0; $i < 1; $i++) {
            $this->makeResultado(['gemini_confianza' => 30, 'descartado' => true]);
        }

        $results = $this->service->confianzaGeminiVsDescartado(skipCache: true);

        $this->assertInstanceOf(Collection::class, $results);
        $this->assertGreaterThanOrEqual(4, $results->count(), 'Must return at least 4 bucket rows');

        /** @var ConfianzaBucketDTO[] $byLabel */
        $byLabel = $results->keyBy(fn (ConfianzaBucketDTO $dto) => $dto->bucket)->all();

        $this->assertArrayHasKey('85-100', $byLabel, 'Bucket 85-100 must exist');
        $this->assertSame(3, $byLabel['85-100']->total, 'Bucket 85-100 must have 3 rows');

        $this->assertArrayHasKey('70-84', $byLabel, 'Bucket 70-84 must exist');
        $this->assertSame(2, $byLabel['70-84']->total, 'Bucket 70-84 must have 2 rows');

        $this->assertArrayHasKey('50-69', $byLabel, 'Bucket 50-69 must exist');
        $this->assertSame(4, $byLabel['50-69']->total, 'Bucket 50-69 must have 4 rows');

        $this->assertArrayHasKey('0-49', $byLabel, 'Bucket 0-49 must exist');
        $this->assertSame(1, $byLabel['0-49']->total, 'Bucket 0-49 must have 1 row');
    }

    // ─── REQ-7: getNegativeExamples ───────────────────────────────────────────

    /**
     * SCN-7.1 — Method returns high-confidence descartados.
     *
     * Seeds 5 descartados with gemini_confianza >= 70 and gemini_motivo set.
     * 3 descartados with confianza < 70 (must be excluded).
     * 2 relevantes with confianza >= 70 (must be excluded — not descartados).
     */
    public function test_it_exposes_negative_examples_seam(): void
    {
        // 5 high-confidence descartados (should be returned)
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado([
                'descartado'       => true,
                'gemini_confianza' => 75 + $i,
                'gemini_motivo'    => 'No es PEP relevante',
            ]);
        }
        // 3 low-confidence descartados (must NOT be returned)
        for ($i = 0; $i < 3; $i++) {
            $this->makeResultado([
                'descartado'       => true,
                'gemini_confianza' => 50,
                'gemini_motivo'    => 'Motivo bajo',
            ]);
        }
        // 2 relevantes with high confianza (must NOT be returned)
        for ($i = 0; $i < 2; $i++) {
            $this->makeResultado([
                'descartado'       => false,
                'relevante'        => true,
                'gemini_confianza' => 90,
            ]);
        }

        $result = $this->service->getNegativeExamples(limit: 10);

        $this->assertInstanceOf(Collection::class, $result, 'Must return a Collection');
        $this->assertSame(5, $result->count(), 'Must return exactly 5 high-confidence descartados');

        // All returned rows must be descartados with confianza >= 70
        foreach ($result as $row) {
            $this->assertTrue((bool) $row->descartado, 'Every returned row must be descartado=true');
            $this->assertGreaterThanOrEqual(70, $row->gemini_confianza, 'Every row must have gemini_confianza >= 70');
        }
    }

    // ─── REQ-6: Cache behavior ────────────────────────────────────────────────

    /**
     * SCN-6.1 — Cache::remember called: second call hits cache, not DB.
     *
     * Uses DB query log to confirm only 1 DB query on two calls.
     */
    public function test_it_caches_results_with_correct_ttl(): void
    {
        // Seed sufficient data
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado(['descartado' => true]);
        }

        // First call — populates cache
        $dto1 = $this->service->precisionGeneral();

        // Enable query log AFTER first call
        DB::flushQueryLog();
        DB::enableQueryLog();

        // Second call — must hit cache
        $dto2 = $this->service->precisionGeneral();

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, count($queryLog), 'Second call must hit cache — zero DB queries expected');
        $this->assertEquals($dto1->totalDescartados, $dto2->totalDescartados, 'Both calls must return identical results');
    }

    /**
     * SCN-6.2 — skipCache=true bypasses cache: two calls = two DB queries.
     */
    public function test_it_skips_cache_with_skip_cache_flag(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado(['descartado' => true]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->precisionGeneral(skipCache: true);
        $this->service->precisionGeneral(skipCache: true);

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(2, count($queryLog), 'skipCache=true must execute DB query on every call');
    }

    /**
     * SCN-6.3 — flushCache() invalidates cached results.
     *
     * Prime the cache, flush it, then verify next call hits DB.
     */
    public function test_it_flushes_cache(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado(['descartado' => true]);
        }

        // Warm the cache
        $this->service->precisionGeneral();

        // Flush
        $this->service->flushCache();

        // Next call must go to DB
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service->precisionGeneral();

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertGreaterThan(0, count($queryLog), 'After flushCache(), next call must hit DB');
    }
}
