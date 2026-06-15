<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds GiST trigram index on gaceta_eventos_pep.persona_nombre_normalizado.
 *
 * Enables efficient fuzzy-name searches via pg_trgm similarity operator (%).
 * Required for the Livewire review queue search and future SISA dedup.
 *
 * CONCURRENTLY: zero-downtime on production (no write lock on the table).
 * withinTransaction = false: PostgreSQL requires CONCURRENTLY outside a transaction.
 *
 * SQLite fallback: regular B-tree index on the column (structurally valid for tests;
 * pg_trgm is a PostgreSQL extension with no SQLite equivalent).
 *
 * Requires: pg_trgm extension (enabled by 2026_05_09_100001_enable_pg_trgm_extension).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_gaceta_eventos_persona_trgm
                 ON gaceta_eventos_pep USING GIST (persona_nombre_normalizado gist_trgm_ops)'
            );
        } else {
            // SQLite fallback: regular index for test environment.
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_gaceta_eventos_persona_trgm
                 ON gaceta_eventos_pep (persona_nombre_normalizado)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_gaceta_eventos_persona_trgm');
        } else {
            DB::statement('DROP INDEX IF EXISTS idx_gaceta_eventos_persona_trgm');
        }
    }
};
