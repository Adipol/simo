<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos_pep', function (Blueprint $table) {
            $table->id();
            $table->char('pais_codigo', 2);
            $table->string('nombre', 150);
            $table->string('categoria', 50);
            $table->string('entidad_tipo', 10);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->foreign('pais_codigo')->references('codigo')->on('paises');
            $table->index(['pais_codigo', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargos_pep');
    }
};
