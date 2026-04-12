<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sitios_web', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500)->unique();
            $table->string('nombre', 200);
            $table->char('pais', 2)->default('BO');
            $table->string('selector_links', 200)->nullable();
            $table->string('selector_article', 200)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps(); // created_at + updated_at

            $table->index('activo');
            $table->index('pais');
            $table->foreign('pais')->references('codigo')->on('paises');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sitios_web');
    }
};
