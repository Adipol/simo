<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultado_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resultado_scraping_id')
                ->constrained('resultados_scraping')
                ->cascadeOnDelete();
            $table->string('nombre', 200);
            $table->string('nombre_normalizado', 200)->nullable();
            $table->string('cargo', 300)->nullable();
            $table->string('categoria', 10)->nullable()->comment('PEP or OPI');
            $table->string('entidad_tipo', 20)->nullable();
            $table->integer('confianza')->default(0);
            $table->string('evento', 30)->nullable()->comment('designacion, renuncia, crimen');
            $table->text('motivo')->nullable();
            $table->boolean('threshold_passed')->default(true);
            $table->timestamps();

            $table->index('resultado_scraping_id');
            $table->index('nombre_normalizado');
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultado_personas');
    }
};
