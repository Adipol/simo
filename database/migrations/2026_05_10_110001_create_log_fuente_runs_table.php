<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_fuente_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fuente_id')
                ->constrained('fuentes')
                ->cascadeOnDelete();
            $table->timestamp('started_at')->nullable(false);
            $table->timestamp('finished_at')->nullable();
            $table->string('estado', 20);
            $table->integer('http_status')->nullable();
            $table->integer('cambios_detectados')->default(0);
            $table->text('error_mensaje')->nullable();
            $table->float('duracion_segundos')->nullable();

            // Per-source ordered history queries
            $table->index(['fuente_id', 'started_at'], 'idx_lfr_fuente_started');
            // Queries by state across fuentes
            $table->index(['estado', 'started_at'], 'idx_lfr_estado_started');
            // Retention sweep
            $table->index('started_at', 'idx_lfr_started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_fuente_runs');
    }
};
