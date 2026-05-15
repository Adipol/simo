<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add composite btree index on resultados_scraping (descartado, gemini_confianza DESC).
 *
 * gemini-negative-examples-prompt design §Migration:
 *  - DescartadosAnalisisService::getNegativeExamples() filters by descartado=true
 *    and orders by gemini_confianza DESC. Without a composite index the query
 *    performs a sequential scan + filesort on every prompt-build call.
 *
 * CONCURRENTLY: allows index creation without locking writes in production.
 *  - $withinTransaction = false: PostgreSQL requires CONCURRENTLY to run
 *    outside a transaction block. This property prevents Laravel's migration
 *    runner from wrapping the statement in BEGIN/COMMIT.
 *  - SQLite does not support CONCURRENTLY; a standard CREATE INDEX is used.
 *
 * Rollback: symmetric DROP INDEX with IF EXISTS — safe on both drivers.
 * Pattern mirrors 2026_05_20_120000_add_sitio_id_idx_to_resultados_scraping.php.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_resultados_scraping_descartado_confianza
                 ON resultados_scraping (descartado, gemini_confianza DESC)'
            );
        } else {
            // SQLite fallback for test environment.
            // CONCURRENTLY is a PostgreSQL extension; SQLite uses standard DDL.
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_resultados_scraping_descartado_confianza
                 ON resultados_scraping (descartado, gemini_confianza DESC)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_resultados_scraping_descartado_confianza');
        } else {
            DB::statement('DROP INDEX IF EXISTS idx_resultados_scraping_descartado_confianza');
        }
    }
};
