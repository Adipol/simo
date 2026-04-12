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
            $table->string('gemini_nombre_normalizado', 300)->nullable()->index()->after('gemini_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->dropIndex(['gemini_nombre_normalizado']);
            $table->dropColumn('gemini_nombre_normalizado');
        });
    }
};
