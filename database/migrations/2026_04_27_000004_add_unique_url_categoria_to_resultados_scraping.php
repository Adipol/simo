<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M3b — Add UNIQUE (url, categoria) constraint to resultados_scraping.
     *
     * This migration MUST run AFTER M3a (2026_04_27_000003_dedup_resultados_scraping.php)
     * because the constraint will fail if duplicate (url, categoria) pairs still exist.
     * Migration timestamp ordering (000003 before 000004) ensures correct sequencing.
     *
     * Design D1:
     *   - NULL categoria values are treated as distinct by both Postgres and SQLite,
     *     so legacy rows with categoria IS NULL will not collide.
     *   - The constraint name 'resultados_scraping_url_categoria_unique' follows
     *     Laravel's snake_case naming convention.
     *
     * Pre-condition: Run `pg_dump simo > backup_pre_dedup.sql` BEFORE deploying
     * to production (data safety gate for M3a dedup step).
     */
    public function up(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->unique(['url', 'categoria'], 'resultados_scraping_url_categoria_unique');
        });
    }

    /**
     * Drops the UNIQUE constraint only — does NOT restore the deduped rows
     * that were deleted by M3a. Restore from backup if a full rollback is needed.
     */
    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->dropUnique('resultados_scraping_url_categoria_unique');
        });
    }
};
