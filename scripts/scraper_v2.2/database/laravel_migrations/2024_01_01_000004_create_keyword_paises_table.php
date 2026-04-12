<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla pivote: keywords <-> países.
     * Si una keyword NO aparece aquí = aplica a TODOS los países (global).
     * Si aparece = solo aplica a los países listados.
     */
    public function up(): void
    {
        Schema::create('keyword_paises', function (Blueprint $table) {
            $table->unsignedBigInteger('keyword_id');
            $table->char('pais', 2);

            $table->primary(['keyword_id', 'pais']);

            $table->foreign('keyword_id')
                ->references('id')
                ->on('palabras_clave')
                ->onDelete('cascade');

            $table->foreign('pais')
                ->references('codigo')
                ->on('paises')
                ->onDelete('cascade');

            $table->index('keyword_id');
            $table->index('pais');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_paises');
    }
};
