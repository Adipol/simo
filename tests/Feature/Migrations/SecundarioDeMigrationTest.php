<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for Migration M2: add_secundario_de_to_resultados_scraping
 * and Migration M3: add_titulo_trgm_index_to_resultados_scraping.
 *
 * P3.T6 — Structural test: verify column, FK, and GiST index exist after migrations.
 *
 * On PostgreSQL: queries information_schema + pg_indexes.
 * On SQLite: verifies column via PRAGMA + index via sqlite_master.
 */
class SecundarioDeMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * P3.T3 — Column secundario_de exists with correct nullable type.
     */
    public function test_secundario_de_column_exists_on_resultados_scraping(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $column = DB::selectOne(
                "SELECT column_name, is_nullable, data_type
                 FROM information_schema.columns
                 WHERE table_name = 'resultados_scraping'
                 AND column_name = 'secundario_de'"
            );

            $this->assertNotNull($column, 'Column secundario_de must exist on resultados_scraping');
            $this->assertSame('YES', $column->is_nullable, 'Column secundario_de must be nullable');
            $this->assertStringContainsString('int', $column->data_type, 'Column secundario_de must be integer type');
        } elseif ($driver === 'sqlite') {
            $columns = DB::select("PRAGMA table_info(resultados_scraping)");
            $secCol = collect($columns)->firstWhere('name', 'secundario_de');

            $this->assertNotNull($secCol, 'Column secundario_de must exist on resultados_scraping (SQLite)');
            // In SQLite PRAGMA, notnull=0 means nullable
            $this->assertSame(0, (int) $secCol->notnull, 'Column secundario_de must be nullable (SQLite)');
        } else {
            $this->markTestSkipped("Column check not implemented for driver: {$driver}");
        }
    }

    /**
     * P3.T3 — Index idx_secundario_de exists on resultados_scraping.
     */
    public function test_idx_secundario_de_index_exists(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $exists = DB::selectOne(
                "SELECT 1 AS found FROM pg_indexes
                 WHERE tablename = 'resultados_scraping'
                 AND indexname = 'idx_secundario_de'"
            );
            $this->assertNotNull($exists, 'Index idx_secundario_de must exist on resultados_scraping (PostgreSQL)');
        } elseif ($driver === 'sqlite') {
            $exists = DB::selectOne(
                "SELECT 1 AS found FROM sqlite_master
                 WHERE type = 'index'
                 AND tbl_name = 'resultados_scraping'
                 AND name = 'idx_secundario_de'"
            );
            $this->assertNotNull($exists, 'Index idx_secundario_de must exist on resultados_scraping (SQLite)');
        } else {
            $this->markTestSkipped("Index check not implemented for driver: {$driver}");
        }
    }

    /**
     * P3.T4 — GiST/GIN index on titulo for pg_trgm similarity.
     *
     * On SQLite: a regular index is created as fallback; its name is verified via sqlite_master.
     */
    public function test_titulo_trgm_index_exists_on_resultados_scraping(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $exists = DB::selectOne(
                "SELECT 1 AS found FROM pg_indexes
                 WHERE tablename = 'resultados_scraping'
                 AND indexname = 'idx_resultados_titulo_trgm'"
            );
            $this->assertNotNull($exists, 'GiST index idx_resultados_titulo_trgm must exist on resultados_scraping (PostgreSQL)');
        } elseif ($driver === 'sqlite') {
            $exists = DB::selectOne(
                "SELECT 1 AS found FROM sqlite_master
                 WHERE type = 'index'
                 AND tbl_name = 'resultados_scraping'
                 AND name = 'idx_resultados_titulo_trgm'"
            );
            $this->assertNotNull($exists, 'Index idx_resultados_titulo_trgm must exist on resultados_scraping (SQLite fallback)');
        } else {
            $this->markTestSkipped("Index check not implemented for driver: {$driver}");
        }
    }

    /**
     * P3.T5 — config_scripts row for dedupe exists after M4.
     */
    public function test_dedupe_config_row_exists_in_config_scripts(): void
    {
        $row = DB::table('config_scripts')->where('script', 'dedupe')->first();

        $this->assertNotNull($row, "Row script='dedupe' must exist in config_scripts after M4 migration");
        $this->assertTrue((bool) $row->habilitado, 'Dedupe config must be habilitado=true');

        $notas = json_decode($row->notas, true);
        $this->assertIsArray($notas, 'notas column must be valid JSON');
        $this->assertArrayHasKey('threshold', $notas, "notas JSON must contain 'threshold' key");
        $this->assertSame(0.90, (float) $notas['threshold'], 'Threshold must be 0.90');
    }
}
