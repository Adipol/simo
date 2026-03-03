<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_scraping', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2000);
            $table->string('keyword', 200);
            $table->foreignId('sitio_id')->nullable()->constrained('sitios_web')->nullOnDelete();
            $table->char('pais', 2)->default('BO');
            $table->string('categoria', 20)->nullable();
            $table->string('titulo', 500)->nullable();
            $table->text('contexto')->nullable();
            $table->timestamp('fecha_encontrado')->useCurrent();

            // Relevancia — escrita por el script Python
            $table->unsignedTinyInteger('relevance_score')->default(0)->comment('0-100');
            $table->boolean('found_in_title')->default(false);

            // Gestion — escrita desde Laravel
            $table->boolean('leido')->default(false);
            $table->boolean('relevante')->nullable();
            $table->text('notas')->nullable();

            $table->index('keyword');
            $table->index('pais');
            $table->index('categoria');
            $table->index('fecha_encontrado');
            $table->index('found_in_title');
            $table->index('relevance_score');
            $table->index('leido');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_scraping');
    }
};
