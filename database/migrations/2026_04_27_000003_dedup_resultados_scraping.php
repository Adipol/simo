<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * M3a — Dedup existing (url, categoria) duplicates in resultados_scraping.
     *
     * Strategy (per design D1):
     *   For each (url, categoria) group with >1 row, KEEP the row with the
     *   highest relevance_score. Ties are broken by MAX(id) (most-recently inserted).
     *   The losing rows are hard-deleted. Child rows in resultado_personas and
     *   clasificaciones_feedback are removed automatically via ON DELETE CASCADE.
     *
     * Rows with categoria IS NULL are excluded from dedup by the WHERE clause —
     * Postgres treats NULLs as distinct in UNIQUE constraints anyway, so they
     * will not block the M3b UNIQUE constraint that follows.
     *
     * DATA DESTRUCTIVE: deleted rows cannot be recovered from this migration alone.
     * Run `pg_dump simo > backup_pre_dedup.sql` BEFORE applying in production.
     *
     * SQLite compatibility: SQLite 3.35+ supports CTE DELETE with ROW_NUMBER()
     * (tested on SQLite 3.49.2 — the PHP bundled version). The same SQL runs on
     * both SQLite (test environment) and PostgreSQL (production).
     */
    public function up(): void
    {
        // Count duplicates before dedup (for log).
        $duplicateCount = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM resultados_scraping
             WHERE categoria IS NOT NULL
             AND (url, categoria) IN (
                 SELECT url, categoria
                 FROM resultados_scraping
                 WHERE categoria IS NOT NULL
                 GROUP BY url, categoria
                 HAVING COUNT(*) > 1
             )'
        );

        $totalDuplicates = (int) ($duplicateCount->cnt ?? 0);

        if ($totalDuplicates === 0) {
            Log::info('M3a dedup: no duplicate (url, categoria) rows found — nothing to delete.');

            return;
        }

        // Delete losing rows — keep the highest relevance_score per (url, categoria),
        // tiebreak by MAX(id).
        DB::statement(
            'WITH ranked AS (
                SELECT id,
                       ROW_NUMBER() OVER (
                           PARTITION BY url, categoria
                           ORDER BY relevance_score DESC, id DESC
                       ) AS rn
                FROM resultados_scraping
                WHERE categoria IS NOT NULL
            )
            DELETE FROM resultados_scraping
            WHERE id IN (SELECT id FROM ranked WHERE rn > 1)'
        );

        Log::info("M3a dedup: deleted {$totalDuplicates} duplicate rows from resultados_scraping (children cascade-deleted automatically).");
    }

    /**
     * Migration M3a is data-destructive — rollback restores schema only via M3b.
     * Restore data from pg_dump backup if needed:
     *   psql simo < backup_pre_dedup.sql
     *
     * This down() is intentionally a no-op: deleted rows cannot be recreated
     * from schema metadata alone.
     */
    public function down(): void
    {
        // Migration M3a is data-destructive — rollback restores schema only via M3b.
        // Restore data from pg_dump backup: psql simo < backup_pre_dedup.sql
        Log::warning(
            'M3a dedup down() called: this migration is data-destructive. ' .
            'Deleted rows are NOT restored. Restore from backup if needed.'
        );
    }
};
