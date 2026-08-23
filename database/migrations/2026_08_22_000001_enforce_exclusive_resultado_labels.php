<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT_NAME = 'resultados_scraping_descartado_relevante_exclusive';

    public function up(): void
    {
        DB::table('resultados_scraping')
            ->where('descartado', true)
            ->where('relevante', true)
            ->update(['relevante' => false]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE resultados_scraping
                 ADD CONSTRAINT '.self::CONSTRAINT_NAME.'
                 CHECK (NOT (descartado IS TRUE AND relevante IS TRUE))'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE resultados_scraping
                 DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT_NAME
            );
        }
    }
};
