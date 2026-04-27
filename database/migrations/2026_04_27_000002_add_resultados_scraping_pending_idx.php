<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Partial index for pendingQuery(): filters gemini_analyzed=false and orders by fecha_encontrado DESC.
        // On a skewed boolean column (most rows will have gemini_analyzed=true after analysis),
        // a partial index is far more selective than a full btree on gemini_analyzed.
        // Coexists with the existing separate btrees on gemini_analyzed and fecha_encontrado.
        DB::statement(
            'CREATE INDEX resultados_scraping_pending_idx ON resultados_scraping (fecha_encontrado DESC) WHERE gemini_analyzed = false'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS resultados_scraping_pending_idx');
    }
};
