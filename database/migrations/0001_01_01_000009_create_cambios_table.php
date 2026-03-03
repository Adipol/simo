<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuente_id')->constrained('fuentes')->cascadeOnDelete();
            $table->timestamp('fecha')->useCurrent();
            $table->string('hash_anterior', 64)->nullable();
            $table->string('hash_nuevo', 64)->nullable();
            $table->integer('lineas_quitadas')->default(0)->comment('Cuantas lineas desaparecieron');
            $table->integer('lineas_nuevas')->default(0)->comment('Cuantas lineas aparecieron');
            $table->mediumText('diff_texto')->nullable()->comment('Diff completo: lineas con - y +');
            $table->text('posibles_peps')->nullable()->comment('Lineas que parecen nombres de persona');
            $table->boolean('revisado')->default(false)->comment('Marcado como revisado desde Laravel');

            $table->index('fuente_id');
            $table->index('fecha');
            $table->index('revisado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios');
    }
};
