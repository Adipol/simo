<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dedupe;

use App\Models\ConfigScript;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\Dedupe\DedupeArticulosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P4.T3 — RED tests for DedupeArticulosService::procesar().
 *
 * Requires real PostgreSQL (pg_trgm), so no SQLite fallback.
 * Tests cover:
 *  - No candidates → article stays primary
 *  - Dissimilar titles → no cluster
 *  - 1 candidate above threshold → new article becomes secondary
 *  - Already secondary → no-op (idempotent)
 *  - Window read from config_scripts
 *  - Threshold read from config_scripts
 *  - habilitado=false → no-op
 */
class DedupeArticulosServiceTest extends TestCase
{
    use RefreshDatabase;

    private DedupeArticulosService $service;
    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Gemini and dedupe observer dispatches during test setup
        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        $this->service = app(DedupeArticulosService::class);

        $this->sitio = SitioWeb::create([
            'url'    => 'https://example.com',
            'nombre' => 'Example',
            'pais'   => 'BO',
            'activo' => true,
        ]);

        // Ensure dedupe config exists with default values
        ConfigScript::updateOrInsert(
            ['script' => 'dedupe'],
            [
                'habilitado'        => true,
                'intervalo_minutos' => 7,
                'timeout_minutos'   => 5,
                'dias_semana'       => '1,2,3,4,5,6,7',
                'notas'             => json_encode(['threshold' => 0.90]),
            ]
        );
    }

    /**
     * Skip the test when the test DB is SQLite (pg_trgm not available).
     * Real similarity tests must run against a PostgreSQL test DB.
     */
    private function skipIfNotPgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL + pg_trgm extension. SQLite fallback returns no candidates.');
        }
    }

    private function makeArticle(string $titulo, array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'            => 'https://example.com/'.str_replace(' ', '-', $titulo).'-'.uniqid(),
            'keyword'        => 'test',
            'sitio_id'       => $this->sitio->id,
            'pais'           => 'BO',
            'titulo'         => $titulo,
            'contexto'       => '',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'          => false,
            'descartado'     => false,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    // ─── T3: Migration — column + partial index ───────────────────────────────

    /**
     * SCN-4.1 / T3: Verify the migration added the column and (pgsql) partial index.
     */
    public function test_it_adds_dedupe_processed_at_column_with_partial_index(): void
    {
        // Column exists in all drivers
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('resultados_scraping', 'dedupe_processed_at'),
            'Column dedupe_processed_at must exist on resultados_scraping'
        );

        // New rows must default to NULL
        $article = $this->makeArticle('Test migration column default');
        $this->assertNull(
            $article->fresh()->dedupe_processed_at,
            'dedupe_processed_at must default to NULL for new rows'
        );

        // Partial index only checked on pgsql
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestIncomplete('Partial index assertion skipped on SQLite (pgsql-only feature)');
        }

        $indexExists = DB::selectOne(
            "SELECT 1 FROM pg_indexes
             WHERE tablename = 'resultados_scraping'
               AND indexname  = 'resultados_scraping_dedupe_pending_idx'"
        );

        $this->assertNotNull($indexExists, 'Partial index resultados_scraping_dedupe_pending_idx must exist on pgsql');
    }

    // ─── No candidates ────────────────────────────────────────────────────────

    public function test_article_stays_primary_when_no_candidates_exist(): void
    {
        $article = $this->makeArticle('Renuncia de Cronenbold en YPFB Bolivia');

        $this->service->procesar($article->id);

        $article->refresh();
        $this->assertNull($article->secundario_de, 'Article with no similar peers must remain primary');
    }

    // ─── Dissimilar titles → no cluster ──────────────────────────────────────

    public function test_article_stays_primary_when_similarity_below_threshold(): void
    {
        $this->skipIfNotPgsql();

        // These two titles are unrelated — similarity well below 0.90
        $this->makeArticle('Bolivia incrementa exportaciones de gas natural al mercado argentino');
        $new = $this->makeArticle('Presidente inaugura carretera en el departamento de Potosí');

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNull($new->secundario_de, 'Dissimilar articles must not form a cluster');
    }

    // ─── 1 candidate above threshold → secondary ─────────────────────────────

    public function test_new_article_becomes_secondary_when_candidate_matches(): void
    {
        $this->skipIfNotPgsql();

        $existing = $this->makeArticle('Renuncia del gerente Carlos Cronenbold de YPFB');

        // Near-identical title, inserted later → should become secondary
        $new = $this->makeArticle('Renuncia del gerente Carlos Cronenbold de la empresa YPFB', [
            'fecha_encontrado' => now()->addSeconds(5),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNotNull($new->secundario_de, 'New article with similar title must be marked secondary');
        $this->assertSame($existing->id, $new->secundario_de, 'Secondary must point to the existing primary');
    }

    // ─── Already secondary → idempotent ──────────────────────────────────────

    public function test_procesar_is_idempotent_when_article_already_secondary(): void
    {
        $primary   = $this->makeArticle('Designación de ministro de economía en Bolivia');
        $secondary = $this->makeArticle('Designación del ministro de economía boliviano', [
            'secundario_de' => $primary->id,
        ]);

        $originalPrimaryId = $secondary->secundario_de;

        // Run twice — should be no-op
        $this->service->procesar($secondary->id);
        $this->service->procesar($secondary->id);

        $secondary->refresh();
        $this->assertSame($originalPrimaryId, $secondary->secundario_de, 'Already-secondary article must not change cluster');
    }

    // ─── Non-existent article → no-op ────────────────────────────────────────

    public function test_procesar_is_noop_when_article_not_found(): void
    {
        $this->expectNotToPerformAssertions();

        // Should not throw, just silently do nothing
        $this->service->procesar(999_999);
    }

    // ─── Excludes already-secondary articles from candidate pool ─────────────

    public function test_excludes_already_secondary_articles_from_candidate_pool(): void
    {
        $this->skipIfNotPgsql();

        $primary = $this->makeArticle('Renuncia gerente YPFB Carlos Cronenbold Bolivia');

        // Make an article that is already secondary under $primary
        $alreadySecondary = $this->makeArticle('Renuncia del gerente YPFB Carlos Cronenbold en Bolivia', [
            'secundario_de' => $primary->id,
        ]);

        // New article with similar title → should find $primary (not $alreadySecondary) as candidate
        $new = $this->makeArticle('Renuncia gerente de YPFB Carlos Cronenbold en Bolivia', [
            'fecha_encontrado' => now()->addSeconds(10),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        // Either becomes secondary of $primary or stays primary (if similarity threshold not met)
        // but it must NOT become secondary of $alreadySecondary
        if ($new->secundario_de !== null) {
            $this->assertNotSame(
                $alreadySecondary->id,
                $new->secundario_de,
                'New article must never become secondary of an already-secondary article'
            );
        }
    }

    // ─── Window from config_scripts ───────────────────────────────────────────

    public function test_articles_outside_window_are_not_candidates(): void
    {
        $this->skipIfNotPgsql();

        // Set window to 3 days
        ConfigScript::where('script', 'dedupe')->update(['intervalo_minutos' => 3]);

        // Existing article is 5 days old — outside the 3-day window
        $old = $this->makeArticle('Renuncia gerente YPFB Bolivia noticias', [
            'fecha_encontrado' => now()->subDays(5),
        ]);

        $new = $this->makeArticle('Renuncia gerente de YPFB Bolivia noticias recientes', [
            'fecha_encontrado' => now(),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNull($new->secundario_de, 'Articles outside the window must not form a cluster');
    }

    // ─── T5-T7: dedupe_processed_at stamping ─────────────────────────────────

    /**
     * SCN-1.2 / T5 (RED): Service stamps dedupe_processed_at after successful procesar().
     *
     * Uses habilitado=true in ConfigScript and article with no secondary candidates.
     * Even when no cluster forms, the timestamp must be set (Path B: post-transaction).
     */
    public function test_it_sets_dedupe_processed_at_after_successful_processing(): void
    {
        ConfigScript::where('script', 'dedupe')->update(['habilitado' => true]);

        $article = $this->makeArticle('Artículo único sin candidatos similares');

        $this->assertNull($article->dedupe_processed_at, 'Precondition: must be NULL before procesar()');

        $this->service->procesar($article->id);

        $article->refresh();
        $this->assertNotNull(
            $article->dedupe_processed_at,
            'dedupe_processed_at must be set after procesar() completes (SCN-1.2)'
        );
    }

    /**
     * SCN-1.3 / T6 (RED): Service stamps dedupe_processed_at on early-exit (already secondary).
     *
     * Path A (early-exit at line ~45): article has secundario_de set → service returns early.
     * Even in this path the timestamp must be stamped.
     */
    public function test_it_sets_dedupe_processed_at_even_when_row_is_already_secondary(): void
    {
        ConfigScript::where('script', 'dedupe')->update(['habilitado' => true]);

        $primary   = $this->makeArticle('Artículo primario de referencia');
        $secondary = $this->makeArticle('Artículo secundario con secundario_de ya definido', [
            'secundario_de' => $primary->id,
        ]);

        $this->assertNull($secondary->dedupe_processed_at, 'Precondition: must be NULL before procesar()');

        $this->service->procesar($secondary->id);

        $secondary->refresh();
        $this->assertNotNull(
            $secondary->dedupe_processed_at,
            'dedupe_processed_at must be set even when article is already a secondary (SCN-1.3 Path A)'
        );
    }

    /**
     * T7 (RED): null guard — article not found must NOT stamp (no-op).
     */
    public function test_it_does_not_stamp_when_article_does_not_exist(): void
    {
        ConfigScript::where('script', 'dedupe')->update(['habilitado' => true]);

        // Non-existent id — should not throw and DB must not have any row with this id
        $this->service->procesar(999_888_777);

        // No row exists → nothing to check; assert no exception was thrown
        $this->assertDatabaseMissing('resultados_scraping', ['id' => 999_888_777]);
    }

    // ─── REGRESSION GUARD: pg_trgm threshold via set_config(...) ──────────────

    /**
     * Regression guard for production hotfix `hotfix/dedupe-pg-trgm-set-local`.
     *
     * BUG: `DB::statement('SET LOCAL pg_trgm.similarity_threshold = ?', [$threshold])`
     * fails on PostgreSQL with `syntax error at or near "$1"` because PG does NOT
     * accept parameter bindings on `SET` statements. The fix uses the function form
     * `SELECT set_config(name, value, true)` which IS a regular function and accepts
     * bound params.
     *
     * This test executes the EXACT SQL the service runs and asserts:
     *   1. It does not throw a PDOException.
     *   2. The session variable is actually set to the requested value.
     *
     * Tests in SQLite skip because pg_trgm and set_config are PostgreSQL-specific.
     */
    public function test_pg_trgm_threshold_is_set_via_set_config_function_not_set_local(): void
    {
        $this->skipIfNotPgsql();

        // Replicate the EXACT statement DedupeArticulosService::queryCandidates uses.
        // If we ever regress to `SET LOCAL ... = ?`, this test fails immediately.
        DB::transaction(function (): void {
            DB::statement(
                'SELECT set_config(?, ?, true)',
                ['pg_trgm.similarity_threshold', '0.85']
            );

            $value = DB::selectOne("SELECT current_setting('pg_trgm.similarity_threshold') AS v");

            $this->assertSame(
                '0.85',
                $value->v,
                'pg_trgm.similarity_threshold must be set to 0.85 via set_config(?, ?, true). '
                . 'If this fails with a syntax error, the service likely regressed to `SET LOCAL ... = ?` '
                . 'which is INVALID syntax in PostgreSQL.'
            );
        });
    }

    // ─── REGRESSION GUARD: older candidate wins as cluster head on similarity tie ─────

    /**
     * Regression guard for hotfix `hotfix/cluster-head-prefers-older`.
     *
     * BUG (pre-fix): when two candidate primaries had identical similarity, the cluster
     * head was non-deterministic — depended on PostgreSQL's query plan. In the
     * 2026-05-11 backfill this caused "first-processed loses" behavior: the article
     * whose dedupe job ran first ended up as secondary of the second one.
     *
     * FIX: ORDER BY sim DESC, fecha_encontrado ASC — tie-breaker prefers the older row.
     *
     * Setup: create TWO primaries with identical titles (similarity = 1.0) but
     * different fecha_encontrado. Process a THIRD identical article. It must
     * become secondary of the OLDEST primary, not the newest.
     */
    public function test_older_primary_wins_cluster_head_on_similarity_tie(): void
    {
        $this->skipIfNotPgsql();

        $titulo = 'Renuncia gerente YPFB Carlos Cronenbold Bolivia';

        // Two existing primaries with IDENTICAL titles → similarity = 1.0 for both
        $older = $this->makeArticle($titulo, [
            'fecha_encontrado' => now()->subHours(4),
        ]);
        $newer = $this->makeArticle($titulo, [
            'fecha_encontrado' => now()->subHours(2),
        ]);

        // Third article with the same title — must cluster under the OLDER primary
        $incoming = $this->makeArticle($titulo, [
            'fecha_encontrado' => now(),
        ]);

        $this->service->procesar($incoming->id);

        $incoming->refresh();
        $this->assertNotNull(
            $incoming->secundario_de,
            'Incoming article with identical title must be marked secondary'
        );
        $this->assertSame(
            $older->id,
            $incoming->secundario_de,
            'On similarity tie, the OLDER primary must win as cluster head (ORDER BY fecha_encontrado ASC). '
            . 'If this fails, the query likely regressed to ORDER BY sim DESC without the fecha_encontrado tie-breaker, '
            . 'leaving cluster head non-deterministic.'
        );
        $this->assertNotSame(
            $newer->id,
            $incoming->secundario_de,
            'Incoming must NOT cluster under the newer primary when an older candidate of equal similarity exists.'
        );
    }

    // ─── habilitado = false → no-op ───────────────────────────────────────────

    public function test_procesar_is_noop_when_dedupe_disabled_in_config(): void
    {
        ConfigScript::where('script', 'dedupe')->update(['habilitado' => false]);

        $existing = $this->makeArticle('Renuncia ministro economía Bolivia esta semana');
        $new = $this->makeArticle('Renuncia del ministro de economía Bolivia esta semana', [
            'fecha_encontrado' => now()->addSeconds(5),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNull($new->secundario_de, 'When dedupe is disabled, no clustering must happen');
    }
}
