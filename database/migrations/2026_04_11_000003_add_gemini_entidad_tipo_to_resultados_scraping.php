<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->string('gemini_entidad_tipo', 15)->nullable()->after('gemini_categoria')
                ->comment('publica | privada | desconocido');
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->dropColumn('gemini_entidad_tipo');
        });
    }
};
