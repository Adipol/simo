<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1: Enable the pg_trgm PostgreSQL extension.
 *
 * Required for: similarity operator (%), GiST index on titulo, and
 * the DedupeArticulosJob query (titulo % :nuevo_titulo).
 *
 * SQLite: no-op (pg_trgm is a PostgreSQL extension; SQLite has no equivalent).
 * withinTransaction: default (true) — CREATE EXTENSION is idempotent and transactional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
        }
    }
};
