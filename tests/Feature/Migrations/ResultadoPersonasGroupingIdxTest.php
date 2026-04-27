<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies that the composite partial index resultado_personas_grouping_idx
 * was created by the migration 2026_04_27_000005_add_resultado_personas_grouping_idx.
 *
 * The index exists on resultado_personas (nombre_normalizado, evento)
 * WHERE threshold_passed = true AND nombre_normalizado IS NOT NULL.
 *
 * SQLite note: RefreshDatabase runs all migrations (including this one) on SQLite in-memory.
 * The migration creates a regular (non-CONCURRENTLY) index on SQLite, so we can verify
 * its presence using sqlite_master. On PostgreSQL we query pg_indexes.
 */
class ResultadoPersonasGroupingIdxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifies that the grouping index exists after migrations are applied.
     *
     * On PostgreSQL, queries pg_indexes for the exact index name.
     * On SQLite, queries sqlite_master.
     * Other drivers: test is skipped.
     */
    public function test_partial_index_exists_on_resultado_personas(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $exists = DB::selectOne(
                "SELECT 1 AS found FROM pg_indexes
                 WHERE tablename = 'resultado_personas'
                 AND indexname = 'resultado_personas_grouping_idx'"
            );
            $this->assertNotNull($exists, 'Index resultado_personas_grouping_idx must exist on resultado_personas (PostgreSQL)');
        } elseif ($driver === 'sqlite') {
            $exists = DB::selectOne(
                "SELECT 1 AS found FROM sqlite_master
                 WHERE type = 'index'
                 AND tbl_name = 'resultado_personas'
                 AND name = 'resultado_personas_grouping_idx'"
            );
            $this->assertNotNull($exists, 'Index resultado_personas_grouping_idx must exist on resultado_personas (SQLite)');
        } else {
            $this->markTestSkipped("Index existence check not implemented for driver: {$driver}");
        }
    }
}
