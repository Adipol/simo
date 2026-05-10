<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M3: Create GiST index on resultados_scraping.titulo for pg_trgm similarity.
 *
 * Design D2: The DedupeArticulosJob uses the pg_trgm % operator to find
 * articles with similar titles. A GiST index using gist_trgm_ops enables
 * efficient index scans for similarity queries.
 *
 * CREATE INDEX CONCURRENTLY: allows index creation without locking writes
 * on production — required for zero-downtime deployments.
 *
 * $withinTransaction = false: PostgreSQL requires CONCURRENTLY to run
 * outside of a transaction block. This property prevents Laravel's migration
 * runner from wrapping the statement in BEGIN/COMMIT.
 *
 * SQLite: creates a regular B-tree index on titulo as a no-op fallback
 * (SQLite has no pg_trgm support; the index is structurally valid for tests).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_resultados_titulo_trgm
                 ON resultados_scraping USING GIST (titulo gist_trgm_ops)'
            );
        } else {
            // SQLite fallback: regular index for test environment.
            // pg_trgm is a PostgreSQL extension; SQLite has no GiST support.
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_resultados_titulo_trgm
                 ON resultados_scraping (titulo)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_resultados_titulo_trgm');
        } else {
            DB::statement('DROP INDEX IF EXISTS idx_resultados_titulo_trgm');
        }
    }
};
