<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('config_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('script', 50)->unique()->comment('scraper | pep_monitor');
            $table->boolean('habilitado')->default(true);
            $table->unsignedSmallInteger('intervalo_minutos')->default(60)->comment('Cada cuantos minutos ejecutar');
            $table->time('hora_inicio')->nullable()->comment('Hora del dia para iniciar (null = sin restriccion)');
            $table->time('hora_fin')->nullable()->comment('Hora del dia para detener (null = sin restriccion)');
            $table->string('dias_semana', 20)->default('1,2,3,4,5,6,7')->comment('1=Lun ... 7=Dom');
            $table->unsignedSmallInteger('timeout_minutos')->default(120)->comment('Maximo tiempo antes de cancelar ejecucion');
            $table->string('notas', 500)->nullable();
            $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_scripts');
    }
};
