<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cambios', function (Blueprint $table) {
            $table->boolean('gemini_analyzed')->default(false)->after('revisado');
            $table->json('gemini_analisis_json')->nullable()->after('gemini_analyzed')
                ->comment('Full Pro analysis: persona_removida, persona_nueva, cargo, es_mae, riesgo, analisis');

            $table->index('gemini_analyzed');
        });
    }

    public function down(): void
    {
        Schema::table('cambios', function (Blueprint $table) {
            $table->dropIndex(['gemini_analyzed']);
            $table->dropColumn(['gemini_analyzed', 'gemini_analisis_json']);
        });
    }
};
