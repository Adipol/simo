<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add btree index on resultados_scraping.sitio_id.
 *
 * feedback-loop-from-descartados design §Migration:
 *  - sitio_id has no btree index; topSitiosProblematicos() aggregates per sitio_id
 *    across the full table — sequential scan is acceptable today (~250 rows) but
 *    will degrade past 10K rows. The index is cheap to add now.
 *
 * CONCURRENTLY: allows index creation without locking writes in production.
 *  - $withinTransaction = false: PostgreSQL requires CONCURRENTLY to run
 *    outside a transaction block. This property prevents Laravel's migration
 *    runner from wrapping the statement in BEGIN/COMMIT.
 *  - SQLite does not support CONCURRENTLY; a standard CREATE INDEX is used.
 *
 * Rollback: symmetric DROP INDEX with IF EXISTS — safe on both drivers.
 * Pattern mirrors 2026_05_09_100003_add_titulo_trgm_index_to_resultados_scraping.php.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_resultados_scraping_sitio_id
                 ON resultados_scraping (sitio_id)'
            );
        } else {
            // SQLite fallback for test environment.
            // CONCURRENTLY is a PostgreSQL extension; SQLite uses standard DDL.
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_resultados_scraping_sitio_id
                 ON resultados_scraping (sitio_id)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_resultados_scraping_sitio_id');
        } else {
            DB::statement('DROP INDEX IF EXISTS idx_resultados_scraping_sitio_id');
        }
    }
};
