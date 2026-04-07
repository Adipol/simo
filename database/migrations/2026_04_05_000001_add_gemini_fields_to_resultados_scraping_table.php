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
            $table->boolean('gemini_analyzed')->default(false)->after('descartado');
            $table->boolean('gemini_is_pep')->nullable()->after('gemini_analyzed');
            $table->string('gemini_nombre', 300)->nullable()->after('gemini_is_pep');
            $table->string('gemini_cargo', 300)->nullable()->after('gemini_nombre');
            $table->string('gemini_categoria', 10)->nullable()->after('gemini_cargo')
                ->comment('PEP | OPI | null');
            $table->unsignedTinyInteger('gemini_confianza')->nullable()->after('gemini_categoria')
                ->comment('0-100');
            $table->text('gemini_motivo')->nullable()->after('gemini_confianza')
                ->comment('Explanation from Flash for classification');

            $table->index('gemini_analyzed');
            $table->index('gemini_categoria');
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table) {
            $table->dropIndex(['gemini_analyzed']);
            $table->dropIndex(['gemini_categoria']);
            $table->dropColumn([
                'gemini_analyzed',
                'gemini_is_pep',
                'gemini_nombre',
                'gemini_cargo',
                'gemini_categoria',
                'gemini_confianza',
                'gemini_motivo',
            ]);
        });
    }
};
