<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuentes', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500)->unique();
            $table->string('nombre', 300)->nullable();
            $table->string('pais', 100)->nullable();
            $table->string('organismo', 300)->nullable();
            $table->enum('nivel', ['nacional', 'regional', 'municipal', 'judicial', 'legislativo', 'otro'])->default('nacional');
            $table->enum('tipo', ['html', 'pdf', 'js'])->default('html');
            $table->boolean('activo')->default(true);
            $table->string('selector_css', 500)->nullable()->comment('Selector CSS para aislar el area de interes');
            $table->timestamp('ultimo_check')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('activo');
            $table->index('pais');
            $table->index('organismo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuentes');
    }
};
