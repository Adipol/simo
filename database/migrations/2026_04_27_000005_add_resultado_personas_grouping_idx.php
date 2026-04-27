<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Composite partial index on resultado_personas for the panel-peps GROUP BY query.
 *
 * Purpose: Supports the grouping query in ResultadoPersonaQueryService which groups
 * by (nombre_normalizado, evento) with WHERE threshold_passed = true AND nombre_normalizado IS NOT NULL.
 * The partial index covers only qualifying rows (threshold_passed=true), which is the
 * minority of rows after filtering, making it far more selective than a full B-tree index.
 *
 * CONCURRENTLY (PostgreSQL only): allows the index to be built without locking writes
 * on production traffic. On SQLite (test environment) we fall back to a regular CREATE INDEX.
 * withinTransaction = false is required by PostgreSQL for CONCURRENTLY builds.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS resultado_personas_grouping_idx
                 ON resultado_personas (nombre_normalizado, evento)
                 WHERE threshold_passed = true AND nombre_normalizado IS NOT NULL'
            );
        } else {
            // SQLite / other drivers: regular index (test environment only)
            DB::statement(
                'CREATE INDEX IF NOT EXISTS resultado_personas_grouping_idx
                 ON resultado_personas (nombre_normalizado, evento)
                 WHERE threshold_passed = 1 AND nombre_normalizado IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS resultado_personas_grouping_idx');
    }
};
