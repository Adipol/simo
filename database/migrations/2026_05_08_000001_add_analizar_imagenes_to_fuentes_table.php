<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuentes', function (Blueprint $table): void {
            $table->boolean('analizar_imagenes')
                ->default(false)
                ->after('selector_css')
                ->comment('Si true, el scraper extrae <img> y los pasa a Gemini multimodal. Solo activar para fuentes que publican PEPs dentro de imágenes (ej: nóminas escaneadas).');
        });
    }

    public function down(): void
    {
        Schema::table('fuentes', function (Blueprint $table): void {
            $table->dropColumn('analizar_imagenes');
        });
    }
};
