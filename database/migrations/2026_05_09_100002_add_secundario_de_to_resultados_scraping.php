<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2: Add secundario_de self-referential FK to resultados_scraping.
 *
 * Design D3: Cluster model — primario has secundario_de IS NULL;
 * secondary articles point to the primary via secundario_de = primario.id.
 *
 * FK ON DELETE SET NULL: if the primary is deleted, secondaries become orphan primaries
 * (their secundario_de is set to NULL, promoting them to primaries automatically).
 *
 * Index idx_secundario_de on (secundario_de) supports:
 *   - WHERE secundario_de = ? (find secondaries of a primary)
 *   - WHERE secundario_de IS NULL (find only primaries — default filter)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table): void {
            $table->foreignId('secundario_de')
                ->nullable()
                ->constrained('resultados_scraping')
                ->nullOnDelete();

            $table->index('secundario_de', 'idx_secundario_de');
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table): void {
            $table->dropForeign(['secundario_de']);
            $table->dropIndex('idx_secundario_de');
            $table->dropColumn('secundario_de');
        });
    }
};
