<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add partial index supporting scopeStranded() on resultados_scraping.
 *
 * stranded-recovery design §Migration:
 *  - StrandedRecoveryService::recover() uses ResultadoScraping::stranded() which
 *    filters: gemini_analyzed = true AND gemini_analyzed_at IS NULL AND
 *             gemini_is_pep IS NULL AND gemini_error_motivo IS NULL.
 *  - Without a partial index the query performs a sequential scan on a column
 *    (gemini_analyzed=true) that covers the majority of the table, making the
 *    four-way NULL predicate unselective at scale.
 *  - A partial index on the rare stranded sub-set (analyzed=true, no result cols)
 *    is highly selective and avoids full-table scans during recovery runs.
 *
 * CONCURRENTLY: allows index creation without locking writes in production.
 *  - $withinTransaction = false: PostgreSQL requires CONCURRENTLY to run
 *    outside a transaction block. This property prevents Laravel's migration
 *    runner from wrapping the statement in BEGIN/COMMIT.
 *  - SQLite does not support CONCURRENTLY or partial WHERE on CREATE INDEX
 *    in all versions; a simplified index is used on that driver.
 *
 * Rollback: symmetric DROP INDEX with IF EXISTS — safe on both drivers.
 * Pattern mirrors 2026_05_15_200000_add_descartado_confianza_idx_to_resultados_scraping.php.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_resultados_scraping_stranded
                 ON resultados_scraping (id)
                 WHERE gemini_analyzed = true
                   AND gemini_analyzed_at IS NULL
                   AND gemini_is_pep IS NULL
                   AND gemini_error_motivo IS NULL"
            );
        } else {
            // SQLite fallback for test environment.
            // CONCURRENTLY and partial WHERE predicates are PostgreSQL extensions;
            // SQLite uses standard DDL with no partial clause.
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_resultados_scraping_stranded
                 ON resultados_scraping (gemini_analyzed, gemini_analyzed_at, gemini_is_pep, gemini_error_motivo)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_resultados_scraping_stranded');
        } else {
            DB::statement('DROP INDEX IF EXISTS idx_resultados_scraping_stranded');
        }
    }
};
