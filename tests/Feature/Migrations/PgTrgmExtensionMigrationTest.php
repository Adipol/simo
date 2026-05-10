<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for Migration M1: enable_pg_trgm_extension.
 *
 * On PostgreSQL: verifies the pg_trgm extension is present after migrations.
 * On SQLite: test is skipped — pg_trgm is a PostgreSQL-only extension.
 */
class PgTrgmExtensionMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * P3.T1 / P3.T2 — pg_trgm extension exists after all migrations apply.
     *
     * On PostgreSQL: queries pg_extension.
     * On SQLite: markTestSkipped (extension concept doesn't apply).
     */
    public function test_pg_trgm_extension_exists_after_migration(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $exists = DB::selectOne(
                "SELECT 1 AS found FROM pg_extension WHERE extname = 'pg_trgm'"
            );
            $this->assertNotNull(
                $exists,
                'pg_trgm extension must be present after enable_pg_trgm_extension migration'
            );
        } else {
            $this->markTestSkipped("pg_trgm extension check only applicable for PostgreSQL driver (current: {$driver})");
        }
    }
}
