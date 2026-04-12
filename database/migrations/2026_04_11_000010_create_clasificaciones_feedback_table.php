<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clasificaciones_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resultado_scraping_id')
                ->constrained('resultados_scraping')
                ->cascadeOnDelete();
            $table->foreignId('usuario_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('tipo', 12);
            $table->json('clasificacion_snapshot');
            $table->boolean('corregido_is_pep')->nullable();
            $table->string('corregido_categoria', 6)->nullable();
            $table->string('corregido_nombre', 200)->nullable();
            $table->string('corregido_cargo', 200)->nullable();
            $table->text('motivo')->nullable();
            $table->timestamps();

            $table->unique(['resultado_scraping_id', 'usuario_id'], 'clasif_fb_unique');
            $table->index('tipo');
            $table->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clasificaciones_feedback');
    }
};
