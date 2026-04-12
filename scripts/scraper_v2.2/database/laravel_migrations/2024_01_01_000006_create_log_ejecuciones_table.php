<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_ejecuciones', function (Blueprint $table) {
            $table->id();
            $table->timestamp('fecha_ejecucion')->useCurrent();
            $table->integer('sitios_procesados')->default(0);
            $table->integer('resultados_encontrados')->default(0);
            $table->integer('errores')->default(0);
            $table->decimal('duracion_segundos', 10, 2)->default(0);

            $table->index('fecha_ejecucion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_ejecuciones');
    }
};
