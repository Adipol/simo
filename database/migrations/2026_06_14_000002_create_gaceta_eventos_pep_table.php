<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the gaceta_eventos_pep table.
 *
 * Child of gaceta_normas (one decree → N appointments).
 * Stores individual PEP appointment events extracted from decree summaries.
 * Trigram index on persona_nombre_normalizado is added in a separate migration
 * (requires withinTransaction=false for CONCURRENTLY on PostgreSQL).
 *
 * cargo_categoria maps to the cargos_pep catalog (pais_codigo + nombre);
 * null means the cargo was not found in the catalog → stays in review queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaceta_eventos_pep', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gaceta_norma_id')->comment('Parent decree');
            $table->char('pais', 2)->comment('Denormalized for query performance');
            $table->string('persona_nombre', 150)->comment('Name as extracted from the sumario');
            $table->string('persona_nombre_normalizado', 150)->comment('Uppercase normalized — used for trigram search');
            $table->string('cargo', 150)->comment('Position/role text as extracted');
            $table->string('cargo_categoria', 50)->nullable()
                ->comment('Mapped category from cargos_pep catalog; null = not found → review');
            $table->string('entidad', 150)->nullable()->comment('Entity/ministry name if extractable');
            $table->string('tipo_evento', 20)->default('designacion')
                ->comment('designacion | cese (cese is a Slice 3 seam)');
            $table->boolean('interino')->default(false)->comment('True if the appointment is interim (INTERINO)');
            $table->string('estado_revision', 20)->default('pendiente')
                ->comment('pendiente | requiere_revision | aprobado | rechazado');
            $table->unsignedBigInteger('revisado_por')->nullable()
                ->comment('Seam: user_id of reviewer — FK added in PR3 Livewire phase');
            $table->timestamp('revisado_at')->nullable()->comment('When the review was recorded');
            $table->timestamps();

            $table->foreign('gaceta_norma_id')
                ->references('id')
                ->on('gaceta_normas')
                ->cascadeOnDelete();

            $table->index('gaceta_norma_id');
            $table->index(['pais', 'estado_revision'], 'gaceta_eventos_pep_pais_estado_idx');
            $table->index(['pais', 'tipo_evento'], 'gaceta_eventos_pep_pais_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaceta_eventos_pep');
    }
};
