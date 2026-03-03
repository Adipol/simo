<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('palabras_clave', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 200)->unique();
            $table->string('categoria', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('fecha_creacion')->useCurrent();

            $table->index('activo');
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palabras_clave');
    }
};
