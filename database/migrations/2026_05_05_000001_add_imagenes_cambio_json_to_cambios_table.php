<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cambios', function (Blueprint $table): void {
            $table->json('imagenes_cambio_json')
                ->nullable()
                ->after('gemini_analisis_json')
                ->comment('Array de {path, sha256, mime_type, src_original, size_bytes} para análisis multimodal');
        });
    }

    public function down(): void
    {
        Schema::table('cambios', function (Blueprint $table): void {
            $table->dropColumn('imagenes_cambio_json');
        });
    }
};
