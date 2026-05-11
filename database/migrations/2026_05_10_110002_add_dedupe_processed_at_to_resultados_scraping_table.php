<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add dedupe_processed_at column to resultados_scraping.
 *
 * dedupe-safety-net design §3:
 *  - TIMESTAMP NULL — NULL means "not yet processed by DedupeArticulosService"
 *  - Default NULL so ALL existing rows become eligible for the safety-net on first run
 *  - pgsql-only partial index on (id) WHERE dedupe_processed_at IS NULL for efficient
 *    safety-net queries without scanning processed rows
 *
 * Rollback is safe: the column is additive and carries no FK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table): void {
            $table->timestamp('dedupe_processed_at')->nullable()->default(null)->after('secundario_de');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX resultados_scraping_dedupe_pending_idx
                 ON resultados_scraping (id)
                 WHERE dedupe_processed_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS resultados_scraping_dedupe_pending_idx');
        }

        Schema::table('resultados_scraping', function (Blueprint $table): void {
            $table->dropColumn('dedupe_processed_at');
        });
    }
};
