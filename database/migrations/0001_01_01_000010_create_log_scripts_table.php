<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla para monitorear el estado de los scripts Python
        // Cada script registra aqui su heartbeat al iniciar y al finalizar
        Schema::create('log_scripts', function (Blueprint $table) {
            $table->id();
            $table->enum('script', ['scraper', 'pep_monitor'])->comment('Cual script se ejecuto');
            $table->enum('estado', ['iniciado', 'completado', 'error'])->default('iniciado');
            $table->timestamp('inicio')->useCurrent();
            $table->timestamp('fin')->nullable();
            $table->decimal('duracion_segundos', 10, 2)->nullable();
            $table->integer('items_procesados')->default(0)->comment('URLs o fuentes procesadas');
            $table->integer('items_resultado')->default(0)->comment('Resultados o cambios encontrados');
            $table->integer('errores')->default(0);
            $table->string('mensaje_error', 500)->nullable();

            $table->index('script');
            $table->index('inicio');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_scripts');
    }
};
