<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_imagenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')
                ->constrained('snapshots')
                ->cascadeOnDelete();
            $table->foreignId('fuente_id')
                ->constrained('fuentes')
                ->cascadeOnDelete();
            $table->string('src', 1024)->comment('URL absoluta de la imagen');
            $table->string('sha256', 64)->comment('SHA-256 del contenido descargado');
            $table->unsignedBigInteger('content_length')->nullable()->comment('Bytes del contenido');
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 64)->nullable()->comment('Header Last-Modified raw');
            $table->string('mime_type', 64)->nullable();
            $table->timestamp('ultima_vez_visto')->useCurrent();
            $table->timestamps();

            $table->unique(['fuente_id', 'src'], 'snapshot_imagenes_fuente_src_unique');
            $table->index('fuente_id');
            $table->index(['fuente_id', 'sha256'], 'snapshot_imagenes_fuente_sha_idx');
            $table->index('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_imagenes');
    }
};
