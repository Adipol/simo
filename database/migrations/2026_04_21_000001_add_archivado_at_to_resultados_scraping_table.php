<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->timestamp('archivado_at')->nullable()->after('descartado');
            $table->index('archivado_at');
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->dropIndex(['archivado_at']);
            $table->dropColumn('archivado_at');
        });
    }
};
