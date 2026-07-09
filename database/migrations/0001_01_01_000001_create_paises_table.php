<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paises', function (Blueprint $table): void {
            $table->char('codigo', 2)->primary();
            $table->string('nombre', 50);
            $table->boolean('activo')->default(true);
            $table->timestamp('fecha_creacion')->useCurrent();
        });

        // Only Bolivia ships active. The other countries are seeded for future
        // multi-country use but start inactive: the scraper iterates active
        // countries, and an active country with no sites configured produces
        // empty "0s" runs in log_scripts (Estado de Scripts noise). Activate a
        // country from Configuración de Países once it has sites.
        DB::table('paises')->insert([
            ['codigo' => 'BO', 'nombre' => 'Bolivia', 'activo' => true],
            ['codigo' => 'HN', 'nombre' => 'Honduras', 'activo' => false],
            ['codigo' => 'SV', 'nombre' => 'El Salvador', 'activo' => false],
            ['codigo' => 'NI', 'nombre' => 'Nicaragua', 'activo' => false],
            ['codigo' => 'PY', 'nombre' => 'Paraguay', 'activo' => false],
            ['codigo' => 'GT', 'nombre' => 'Guatemala', 'activo' => false],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('paises');
    }
};
