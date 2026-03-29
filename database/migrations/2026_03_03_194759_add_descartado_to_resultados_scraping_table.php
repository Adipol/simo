<?php

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
            $table->boolean('descartado')->default(false)->after('relevante');
            $table->index('descartado');
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->dropIndex(['descartado']);
            $table->dropColumn('descartado');
        });
    }
};
