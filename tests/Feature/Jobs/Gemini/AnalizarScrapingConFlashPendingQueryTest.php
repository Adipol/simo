<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Gemini;

use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFlashEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the pendingQuery() contract inside AnalizarScrapingConFlash.
 *
 * Also contains a structural test that verifies the partial index M2
 * (resultados_scraping_pending_idx) was applied and exists in the database.
 *
 * NOTE: EXPLAIN ANALYZE on the local empty database shows a Seq Scan — expected.
 * The planner only picks the partial index when the table has real data (skewed
 * boolean: mostly gemini_analyzed=true). Real benchmark must be run on VPS/staging
 * after deploying with production data.
 *
 * EXPLAIN output captured locally (2026-04-27, empty DB, 0 rows):
 *   Limit (cost=10.37..10.39 rows=10 width=3808) (actual time=0.064..0.067 rows=0 loops=1)
 *     -> Sort (cost=10.37..10.39 rows=10 width=3808) (actual time=0.063..0.066 rows=0 loops=1)
 *          Sort Key: fecha_encontrado DESC
 *          Sort Method: quicksort Memory: 25kB
 *          -> Seq Scan on resultados_scraping (... rows=0 ...)
 *               Filter: (NOT gemini_analyzed)
 *   Execution Time: 0.119 ms
 * Once staging has 100k+ rows (mostly analyzed), the planner should switch to
 * Index Scan on resultados_scraping_pending_idx.
 */
class AnalizarScrapingConFlashPendingQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Gemini to avoid observer dispatching real jobs when creating records.
        config(['services.gemini.enabled' => false]);

        // Flush event listeners so createRecord() does not trigger the observer chain.
        ResultadoScraping::flushEventListeners();
    }

    // -------------------------------------------------------------------------
    // 1.D.2 — Structural: partial index M2 must exist in the DB (PostgreSQL only)
    // -------------------------------------------------------------------------

    /**
     * Verifies that migration M2 created the partial index.
     * Skipped on SQLite (test environment) — pg_indexes is a PostgreSQL system catalog.
     * This test MUST be run against the staging/VPS PostgreSQL database to confirm.
     */
    public function test_partial_index_exists(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'pg_indexes is a PostgreSQL system catalog. '.
                'Re-run this test on staging/VPS to verify the index was applied. '.
                'Verified locally via tinker: SELECT 1 FROM pg_indexes WHERE indexname = \'resultados_scraping_pending_idx\' → row returned.'
            );
        }

        $exists = DB::selectOne(
            "SELECT 1 AS x FROM pg_indexes WHERE indexname = 'resultados_scraping_pending_idx'"
        );

        $this->assertNotNull(
            $exists,
            'Partial index resultados_scraping_pending_idx must exist. Run: php artisan migrate'
        );
    }

    // -------------------------------------------------------------------------
    // 1.D.3 — Behavioral: pendingQuery() contract (filter + ordering)
    // -------------------------------------------------------------------------

    public function test_eligibility_requires_processed_primary_when_dedupe_enabled(): void
    {
        config(['services.dedupe.enabled' => true]);

        $eligible = $this->createRecord(['dedupe_processed_at' => now()]);
        $this->createRecord();
        $this->createRecord(['gemini_analyzed' => true, 'dedupe_processed_at' => now()]);
        $this->createRecord([
            'dedupe_processed_at' => now(),
            'secundario_de' => $eligible->id,
        ]);

        $results = app(GeminiFlashEligibilityService::class)->query()->get();

        $this->assertCount(1, $results);
        $this->assertSame($eligible->id, $results->first()->id);
    }

    public function test_eligibility_uses_normal_pending_semantics_when_dedupe_disabled(): void
    {
        config(['services.dedupe.enabled' => false]);

        $pending = $this->createRecord();
        $this->createRecord(['gemini_analyzed' => true]);
        $this->createRecord(['secundario_de' => $pending->id]);

        $results = app(GeminiFlashEligibilityService::class)->query()->get();

        $this->assertCount(1, $results);
        $this->assertSame($pending->id, $results->first()->id);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/'.uniqid(),
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'titulo' => 'Artículo de prueba',
            'contexto' => 'Contexto de prueba para el registro.',
            'relevance_score' => 70,
            'gemini_analyzed' => false,
        ], $overrides));
    }
}
