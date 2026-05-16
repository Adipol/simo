<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dashboard;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardHealthService;
use App\Services\Dashboard\DashboardSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * pgsql-only regression tests for session_tz-agnostic SQL.
 *
 * These tests verify that dashboard queries are stable regardless of the
 * pgsql cluster's session_tz setting. They SKIP on non-pgsql drivers because
 * the regression (session_tz drift) only manifests in PostgreSQL.
 *
 * REQ-7: DashboardHealthService 24h window session_tz stability.
 * REQ-8: DashboardSummaryService hero aging score session_tz stability.
 *
 * Covered: T-A (REQ-7 inclusion), T-B (REQ-7 exclusion), T-C (REQ-8 hero).
 */
class DashboardPortabilityTest extends TestCase
{
    use RefreshDatabase;

    private DashboardHealthService $healthService;

    private DashboardSummaryService $summaryService;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgsql-only regression test — session_tz semantics do not apply to SQLite.');
        }

        Cache::flush();
        config(['dashboard.health_cache_ttl' => 0]);
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);
        Carbon::setTestNow(Carbon::now());

        $cache = new DashboardCacheManager;
        $this->healthService = new DashboardHealthService($cache);
        $this->summaryService = new DashboardSummaryService($cache);
    }

    private function createFuente(): Fuente
    {
        return Fuente::create([
            'url' => 'https://gobierno.bo/portability-'.uniqid(),
            'nombre' => 'Test Portability',
            'organismo' => 'Test',
            'pais' => 'BO',
        ]);
    }

    // =========================================================================
    // T-A — REQ-7: rows inside 24h window are included under UTC session_tz
    // =========================================================================

    /**
     * When the pgsql cluster session_tz is UTC but app.timezone is America/La_Paz,
     * cambios with fecha inside the 24h window (in app_tz) MUST be counted.
     *
     * Pre-fix: the raw `fecha >= NOW() - INTERVAL '24 hours'` comparison compared
     * an unzoned timestamp against a timestamptz, making the result session_tz-
     * dependent. With UTC session_tz and La_Paz app_tz, rows near boundaries
     * could be incorrectly excluded (or day-boundary-shifted).
     *
     * Post-fix: `(fecha AT TIME ZONE 'America/La_Paz') >= NOW() - INTERVAL '24 hours'`
     * normalises the comparison to the app_tz regardless of session_tz.
     */
    public function test_health_latency_window_includes_rows_inside_24h_under_utc_session_tz(): void
    {
        DB::statement("SET TIME ZONE 'UTC'");

        $fuente = $this->createFuente();

        // Create 10 cambios with fecha = 20h ago — inside the 24h window in any tz
        for ($i = 0; $i < 10; $i++) {
            Cambio::flushEventListeners();
            $fecha = Carbon::now()->subHours(20);
            Cambio::create([
                'fuente_id' => $fuente->id,
                'fecha' => $fecha,
                'diff_texto' => 'portability test diff '.$i,
                'gemini_analyzed' => true,
                'gemini_analyzed_at' => $fecha->copy()->addMinutes(5),
            ]);
        }

        $health = $this->healthService->getHealth();

        $this->assertTrue(
            $health->latency->available,
            'latency must be available: 10 rows inside 24h window should be counted'
        );
        $this->assertSame(
            10,
            $health->latency->sample_size,
            'all 10 rows inside 24h window must be included regardless of session_tz'
        );
        $this->assertGreaterThan(0, $health->latency->p50_seconds, 'p50 must be positive — negative value indicates asymmetric AT TIME ZONE regression');
        $this->assertLessThan(86400, $health->latency->p50_seconds, 'p50 must be < 1 day (sanity)');
        $this->assertGreaterThan(0, $health->latency->p95_seconds, 'p95 must be positive — negative value indicates asymmetric AT TIME ZONE regression');
        $this->assertLessThan(86400, $health->latency->p95_seconds, 'p95 must be < 1 day (sanity)');
    }

    // =========================================================================
    // T-B — REQ-7: rows outside 24h window are excluded under UTC session_tz
    // =========================================================================

    /**
     * When pgsql session_tz is UTC, cambios with fecha 25h ago (outside the 24h
     * window in any tz) MUST be excluded.
     */
    public function test_health_latency_window_excludes_rows_outside_24h_under_utc_session_tz(): void
    {
        DB::statement("SET TIME ZONE 'UTC'");

        $fuente = $this->createFuente();

        // Create 10 cambios with fecha = 25h ago — outside the 24h window
        for ($i = 0; $i < 10; $i++) {
            Cambio::flushEventListeners();
            $fecha = Carbon::now()->subHours(25);
            Cambio::create([
                'fuente_id' => $fuente->id,
                'fecha' => $fecha,
                'diff_texto' => 'old portability test diff '.$i,
                'gemini_analyzed' => true,
                'gemini_analyzed_at' => $fecha->copy()->addMinutes(5),
            ]);
        }

        $health = $this->healthService->getHealth();

        $this->assertFalse(
            $health->latency->available,
            'latency must be unavailable: all rows are outside 24h window'
        );
        $this->assertSame(
            0,
            $health->latency->sample_size,
            'no rows should be counted when all are outside 24h window'
        );
    }

    // =========================================================================
    // T-C — REQ-8: hero aging score correct under UTC session_tz
    // =========================================================================

    /**
     * When pgsql session_tz is UTC and app.timezone is America/La_Paz, the hero
     * card MUST select the most recent cambio (cambio B, 2h ago) over the older
     * one (cambio A, 20h ago) based on aging score.
     *
     * With aging_divisor=1 and zero other weights, score = EXTRACT(DAY FROM NOW()-fecha).
     * Both A (20h) and B (2h) have 0 elapsed days in any reasonable tz, so score=0 for
     * both. Tiebreaker is `fecha DESC` → B (more recent) wins.
     *
     * Pre-fix regression: without AT TIME ZONE normalisation, session_tz=UTC could
     * shift fecha interpretation so that A at 20h appears to be 1 day ago (if near
     * a midnight boundary in the configured tz), giving A a higher aging score and
     * making it the (wrong) hero.
     */
    public function test_summary_hero_aging_score_correct_under_utc_session_tz(): void
    {
        DB::statement("SET TIME ZONE 'UTC'");

        Cache::flush();
        config(['dashboard.summary_cache_ttl' => 0]);
        config([
            'dashboard.hero_formula' => [
                'aging_divisor' => 1,
                'riesgo_alto_weight' => 0,
                'es_mae_weight' => 0,
            ],
        ]);

        $fuente = $this->createFuente();

        // Cambio A — 20h ago (older, higher aging risk near boundary)
        Cambio::flushEventListeners();
        $cambioA = Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => Carbon::now()->subHours(20),
            'diff_texto' => 'cambio A - older',
            'revisado' => false,
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Person A', 'riesgo' => 'bajo', 'es_mae' => false],
        ]);

        // Cambio B — 2h ago (more recent, should win on fecha DESC tiebreaker)
        Cambio::flushEventListeners();
        $cambioB = Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => Carbon::now()->subHours(2),
            'diff_texto' => 'cambio B - newer',
            'revisado' => false,
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Person B', 'riesgo' => 'bajo', 'es_mae' => false],
        ]);

        $snapshot = $this->summaryService->getSnapshot();

        $this->assertNotNull($snapshot->hero, 'hero must not be null — both cambios have persona');
        $this->assertSame(
            $cambioB->id,
            $snapshot->hero->id,
            'hero must be cambio B (more recent fecha) — aging score ties at 0, tiebreaker is fecha DESC'
        );
        $this->assertNotSame(
            $cambioA->id,
            $snapshot->hero->id,
            'cambio A (older) must NOT be selected as hero'
        );
    }
}
