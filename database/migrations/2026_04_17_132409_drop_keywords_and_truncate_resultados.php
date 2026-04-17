<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean start — truncate scraping results
        DB::table('resultados_scraping')->truncate();

        // Drop keyword tables (order matters: pivot first, then main)
        Schema::dropIfExists('keyword_paises');
        Schema::dropIfExists('palabras_clave');
    }

    public function down(): void
    {
        // Recreate tables if rollback needed
        Schema::create('palabras_clave', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 200);
            $table->string('categoria', 50)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique('keyword');
        });

        Schema::create('keyword_paises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_id')->constrained('palabras_clave')->cascadeOnDelete();
            $table->string('pais', 10);
            $table->unique(['keyword_id', 'pais']);
        });
    }
};
