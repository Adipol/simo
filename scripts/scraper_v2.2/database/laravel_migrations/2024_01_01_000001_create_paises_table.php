<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paises', function (Blueprint $table) {
            $table->char('codigo', 2)->primary();
            $table->string('nombre', 50);
            $table->boolean('activo')->default(true);
            $table->timestamp('fecha_creacion')->useCurrent();
        });

        // Países iniciales
        DB::table('paises')->insert([
            ['codigo' => 'BO', 'nombre' => 'Bolivia'],
            ['codigo' => 'HN', 'nombre' => 'Honduras'],
            ['codigo' => 'SV', 'nombre' => 'El Salvador'],
            ['codigo' => 'NI', 'nombre' => 'Nicaragua'],
            ['codigo' => 'PY', 'nombre' => 'Paraguay'],
            ['codigo' => 'GT', 'nombre' => 'Guatemala'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('paises');
    }
};
