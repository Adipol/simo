<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisiones_remocion_autoridades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fuente_id')->constrained('fuentes')->cascadeOnDelete();
            $table->foreignId('snapshot_base_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->foreignId('snapshot_observado_id')->nullable()->constrained('snapshots')->nullOnDelete();
            $table->string('origen', 50);
            $table->unsignedSmallInteger('version_esquema')->default(1);
            $table->json('linea_base_json');
            $table->json('candidato_json');
            $table->json('eventos_propuestos_json');
            $table->json('evidencia_json');
            $table->string('fingerprint', 64);
            $table->unsignedBigInteger('lifecycle_key')->default(0);
            $table->string('estado', 20)->default('pending');
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_at')->nullable();
            $table->json('evidencia_decision_json')->nullable();
            $table->foreignId('cambio_confirmado_id')->nullable()->unique()->constrained('cambios')->nullOnDelete();
            $table->timestamp('analisis_despachado_at')->nullable();
            $table->timestamps();

            $table->unique(['fuente_id', 'fingerprint', 'lifecycle_key'], 'revision_remocion_fingerprint_lifecycle_unique');
            $table->index(['estado', 'created_at']);
            $table->index(['origen', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisiones_remocion_autoridades');
    }
};
