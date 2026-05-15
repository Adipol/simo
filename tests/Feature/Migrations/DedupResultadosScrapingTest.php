<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests for Migration M3a (dedup_resultados_scraping).
 *
 * Strategy: RefreshDatabase runs ALL migrations (including M3b UNIQUE constraint).
 * To test dedup behaviour we need to seed duplicate rows BEFORE the constraint exists.
 * We achieve this by:
 *   1. Temporarily dropping the UNIQUE constraint (SQLite: drop+recreate table column;
 *      PostgreSQL: DROP INDEX CONCURRENTLY is DDL — but on SQLite we use DB::statement
 *      to drop and restore the index).
 *   2. Inserting duplicate rows directly via DB::table (bypasses Eloquent events).
 *   3. Re-running the M3a migration class directly via its up() method.
 *   4. Asserting the expected survivors.
 *   5. Restoring state (RefreshDatabase handles teardown anyway).
 *
 * On SQLite, UNIQUE constraints are implemented as indexes. We can drop and
 * recreate them via Schema::dropIndex / Schema::unique. Since RefreshDatabase
 * resets the entire DB after each test, teardown is automatic.
 *
 * NOTE: The NULLS LAST ordering in ROW_NUMBER() OVER (...) is a PostgreSQL extension.
 * On SQLite 3.35+ we emit the CTE without NULLS LAST (SQLite handles NULLs naturally
 * in ORDER BY — NULLs sort first in ASC, last in DESC by default, matching intent).
 */
class DedupResultadosScrapingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Gemini so creating ResultadoScraping rows does not dispatch jobs.
        config(['services.gemini.enabled' => false]);
    }

    // =========================================================================
    // Helper — insert raw rows directly (bypassing Eloquent + UNIQUE constraint)
    // =========================================================================

    /**
     * Temporarily drop the UNIQUE constraint (M3b) so we can seed duplicates,
     * then run M3a to dedup them.
     *
     * Cross-driver via Schema::table + dropUnique — Laravel handles the
     * SQLite (drop index) vs pgsql (drop constraint) difference internally.
     *
     * IMPORTANT: previous implementation used `DB::statement('DROP INDEX...')`
     * which works on SQLite but fails on pgsql (UNIQUE is a CONSTRAINT, not
     * a bare INDEX). The failing DROP aborted the transaction, causing 5 tests
     * to cascade-fail with SQLSTATE[25P02].
     */
    private function dropUniqueConstraint(): void
    {
        try {
            Schema::table('resultados_scraping', function (Blueprint $table): void {
                $table->dropUnique('resultados_scraping_url_categoria_unique');
            });
        } catch (\Throwable) {
            // Constraint might not exist yet in some test orders — safe to ignore.
        }
    }

    private function restoreUniqueConstraint(): void
    {
        try {
            Schema::table('resultados_scraping', function (Blueprint $table): void {
                $table->unique(['url', 'categoria'], 'resultados_scraping_url_categoria_unique');
            });
        } catch (\Throwable) {
            // Already exists or not applicable.
        }
    }

    /**
     * Insert a minimal resultados_scraping row via DB::table (no Eloquent events,
     * no unique check interference). Returns the inserted id.
     */
    private function insertRow(string $url, ?string $categoria, int $relevanceScore, ?int $id = null): int
    {
        $data = [
            'url'             => $url,
            'keyword'         => 'test-keyword',
            'pais'            => 'BO',
            'categoria'       => $categoria,
            'relevance_score' => $relevanceScore,
            'found_in_title'  => 0,
            'leido'           => 0,
            'descartado'      => 0,
            'gemini_analyzed' => 0,
            'fecha_encontrado' => now()->toDateTimeString(),
        ];

        DB::table('resultados_scraping')->insert($data);

        return (int) DB::table('resultados_scraping')
            ->where('url', $url)
            ->where('relevance_score', $relevanceScore)
            ->max('id');
    }

    /**
     * Run M3a dedup migration directly (call up() on the migration class instance).
     */
    private function runDedupMigration(): void
    {
        $migration = require database_path('migrations/2026_04_27_000003_dedup_resultados_scraping.php');
        $migration->up();
    }

    // =========================================================================
    // Tests
    // =========================================================================

    /**
     * 2.A.2 Test 1: Keeps the row with the highest relevance_score per (url, categoria) group.
     */
    public function test_dedup_keeps_highest_relevance_score_per_url_categoria(): void
    {
        $this->dropUniqueConstraint();

        $url = 'https://example.com/dedup-test-1';
        $cat = 'PEP';

        // 3 rows: same (url, categoria), different scores.
        $this->insertRow($url, $cat, 30);
        $highId = $this->insertRow($url, $cat, 90); // ← winner
        $this->insertRow($url, $cat, 60);

        $this->assertSame(3, (int) DB::table('resultados_scraping')->where('url', $url)->count(), 'Pre-condition: 3 rows exist');

        $this->runDedupMigration();

        $survivors = DB::table('resultados_scraping')->where('url', $url)->get();
        $this->assertCount(1, $survivors, 'Only 1 row should survive after dedup');
        $this->assertSame(90, (int) $survivors->first()->relevance_score, 'Survivor must have the highest relevance_score');
        $this->assertSame($highId, (int) $survivors->first()->id, 'Survivor must be the row with relevance_score=90');

        $this->restoreUniqueConstraint();
    }

    /**
     * 2.A.2 Test 2: Tiebreaks by MAX(id) when relevance_score is equal.
     */
    public function test_dedup_tiebreaks_by_max_id_when_relevance_score_equal(): void
    {
        $this->dropUniqueConstraint();

        $url = 'https://example.com/dedup-test-2';
        $cat = 'OPI';

        $lowerId  = $this->insertRow($url, $cat, 75); // inserted first → lower id
        $higherId = $this->insertRow($url, $cat, 75); // inserted second → higher id (winner)

        $this->assertSame(2, (int) DB::table('resultados_scraping')->where('url', $url)->count(), 'Pre-condition: 2 rows exist');

        $this->runDedupMigration();

        $survivors = DB::table('resultados_scraping')->where('url', $url)->get();
        $this->assertCount(1, $survivors, 'Only 1 row should survive after tiebreak');
        $this->assertSame($higherId, (int) $survivors->first()->id, 'Survivor must be the row with the highest id (most recently inserted)');
        $this->assertNotEquals($lowerId, (int) $survivors->first()->id, 'Lower id row must be deleted');

        $this->restoreUniqueConstraint();
    }

    /**
     * 2.A.2 Test 3: Rows with unique (url, categoria) combinations are NOT touched.
     */
    public function test_dedup_does_not_touch_unique_url_categoria_combinations(): void
    {
        $this->dropUniqueConstraint();

        // 5 rows with distinct (url, categoria) pairs — no duplicates.
        $ids = [
            $this->insertRow('https://a.com/1', 'PEP', 50),
            $this->insertRow('https://a.com/2', 'PEP', 60),
            $this->insertRow('https://a.com/1', 'OPI', 70), // same url, diff categoria
            $this->insertRow('https://b.com/1', 'PEP', 80),
            $this->insertRow('https://c.com/1', 'OPI', 90),
        ];

        $this->assertSame(5, (int) DB::table('resultados_scraping')->whereIn('id', $ids)->count(), 'Pre-condition: 5 unique rows');

        $this->runDedupMigration();

        $survivors = DB::table('resultados_scraping')->whereIn('id', $ids)->count();
        $this->assertSame(5, (int) $survivors, 'All 5 rows with distinct (url, categoria) must survive');

        $this->restoreUniqueConstraint();
    }

    /**
     * 2.A.2 Test 4: Deleting a duplicate also cascade-deletes its resultado_personas children.
     */
    public function test_dedup_cascades_children_to_deleted_rows(): void
    {
        $this->dropUniqueConstraint();

        $url = 'https://example.com/dedup-cascade-test';
        $cat = 'PEP';

        // 2 duplicate rows.
        $loserId  = $this->insertRow($url, $cat, 40); // lower score → will be deleted
        $winnerId = $this->insertRow($url, $cat, 95); // higher score → survives

        // Attach a child row to the loser.
        DB::table('resultado_personas')->insert([
            'resultado_scraping_id' => $loserId,
            'nombre'                => 'Persona Test',
            'confianza'             => 80,
            'threshold_passed'      => 1,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $this->assertSame(1, (int) DB::table('resultado_personas')->where('resultado_scraping_id', $loserId)->count(), 'Pre-condition: child row exists for loser');

        $this->runDedupMigration();

        // Loser parent gone.
        $this->assertSame(0, (int) DB::table('resultados_scraping')->where('id', $loserId)->count(), 'Loser parent row must be deleted');

        // Loser child cascade-deleted.
        $this->assertSame(0, (int) DB::table('resultado_personas')->where('resultado_scraping_id', $loserId)->count(), 'Child row of deleted parent must be cascade-deleted');

        // Winner survives.
        $this->assertSame(1, (int) DB::table('resultados_scraping')->where('id', $winnerId)->count(), 'Winner parent row must survive');

        $this->restoreUniqueConstraint();
    }

    /**
     * 2.A.2 Test 5: Rows with NULL categoria are NOT touched by the dedup (WHERE categoria IS NOT NULL).
     * These rows are excluded from the dedup CTE by design — Postgres treats NULLs as
     * distinct in UNIQUE constraints so they won't block M3b.
     */
    public function test_dedup_preserves_null_categoria_rows(): void
    {
        $this->dropUniqueConstraint();

        $url = 'https://example.com/null-categoria';

        // 3 rows: same URL, all NULL categoria (legacy rows).
        $id1 = $this->insertRow($url, null, 50);
        $id2 = $this->insertRow($url, null, 70);
        $id3 = $this->insertRow($url, null, 90);

        $this->assertSame(3, (int) DB::table('resultados_scraping')->where('url', $url)->whereNull('categoria')->count(), 'Pre-condition: 3 NULL-categoria rows');

        $this->runDedupMigration();

        $survivors = DB::table('resultados_scraping')->where('url', $url)->whereNull('categoria')->count();
        $this->assertSame(3, (int) $survivors, 'All 3 NULL-categoria rows must survive — they are excluded from dedup by WHERE categoria IS NOT NULL');

        $this->restoreUniqueConstraint();
    }
}
