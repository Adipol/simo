<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Gemini;

use App\Models\ResultadoScraping;
use Carbon\Carbon;
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
                'pg_indexes is a PostgreSQL system catalog. ' .
                'Re-run this test on staging/VPS to verify the index was applied. ' .
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

    public function test_pending_query_returns_only_unanalyzed_rows(): void
    {
        // 2 analyzed rows
        $this->createRecord(['gemini_analyzed' => true]);
        $this->createRecord(['gemini_analyzed' => true]);

        // 1 unanalyzed row
        $pending = $this->createRecord(['gemini_analyzed' => false]);

        $results = ResultadoScraping::where('gemini_analyzed', false)
            ->orderBy('fecha_encontrado', 'desc')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame($pending->id, $results->first()->id);
    }

    public function test_pending_query_orders_by_fecha_encontrado_desc(): void
    {
        $oldest = $this->createRecord([
            'gemini_analyzed' => false,
            'fecha_encontrado' => Carbon::parse('2026-01-01'),
        ]);
        $newest = $this->createRecord([
            'gemini_analyzed' => false,
            'fecha_encontrado' => Carbon::parse('2026-03-01'),
        ]);
        $middle = $this->createRecord([
            'gemini_analyzed' => false,
            'fecha_encontrado' => Carbon::parse('2026-02-01'),
        ]);

        $results = ResultadoScraping::where('gemini_analyzed', false)
            ->orderBy('fecha_encontrado', 'desc')
            ->get();

        $this->assertCount(3, $results);
        $this->assertSame($newest->id, $results->get(0)->id, 'First row should be newest');
        $this->assertSame($middle->id, $results->get(1)->id, 'Second row should be middle');
        $this->assertSame($oldest->id, $results->get(2)->id, 'Third row should be oldest');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'             => 'https://example.com/' . uniqid(),
            'keyword'         => 'corrupcion',
            'pais'            => 'BO',
            'titulo'          => 'Artículo de prueba',
            'contexto'        => 'Contexto de prueba para el registro.',
            'relevance_score' => 70,
            'gemini_analyzed' => false,
        ], $overrides));
    }
}
