<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fuente_id')->constrained('fuentes')->cascadeOnDelete();
            $table->string('hash', 64)->comment('SHA-256 del texto limpio');
            $table->mediumText('texto')->comment('Texto visible limpio, una linea por elemento');
            $table->string('metodo', 50)->nullable()->comment('html_estatico, js_playwright, pdf');
            $table->timestamp('fecha')->useCurrent();

            $table->index('fuente_id');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
