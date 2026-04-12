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
            $table->string('categoria', 20)->nullable(); // PEP, OPI, etc.
            $table->string('titulo', 500)->nullable();
            $table->text('contexto')->nullable();
            $table->timestamp('fecha_encontrado')->useCurrent();

            // Relevancia (escrito por el scraper Python)
            $table->smallInteger('relevance_score')->default(0); // 0-100
            $table->boolean('found_in_title')->default(false);

            // Gestión (escrito desde Laravel)
            $table->boolean('leido')->default(false);
            $table->boolean('relevante')->nullable();
            $table->text('notas')->nullable();

            // Índices
            $table->index('keyword');
            $table->index('sitio_id');
            $table->index('fecha_encontrado');
            $table->index('found_in_title');
            $table->index('relevance_score');
            $table->index('pais');
            $table->index('categoria');
            $table->index('leido');

            // Evita duplicados: mismo artículo + misma keyword
            // NOTA PostgreSQL: si las URLs superan ~2700 bytes, ver comentario al final
            $table->unique(['url', 'keyword'], 'idx_url_keyword_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_scraping');
    }
};

/*
 * Si al migrar obtienes error "index row size exceeds maximum" (URLs muy largas),
 * reemplaza la línea unique() por este índice funcional en PostgreSQL.
 * Ejecutar manualmente después del migrate:
 *
 * CREATE UNIQUE INDEX idx_url_keyword_unique
 *   ON resultados_scraping (md5(url), keyword);
 */
