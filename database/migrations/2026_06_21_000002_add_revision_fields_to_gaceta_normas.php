<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds revision-tracking columns to gaceta_normas.
 *
 * These columns allow the Gaceta normas review queue to record who resolved
 * a flagged decree (requiere_revision / requiere_detalle) and when, mirroring
 * the same pattern used on gaceta_eventos_pep (no FK constraint — seam column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gaceta_normas', function (Blueprint $table): void {
            $table->unsignedBigInteger('revisado_por')->nullable()
                ->comment('User who resolved the flagged norma (references users.id, no FK constraint)');
            $table->timestamp('revisado_at')->nullable()
                ->comment('When the review decision was recorded');
        });
    }

    public function down(): void
    {
        Schema::table('gaceta_normas', function (Blueprint $table): void {
            $table->dropColumn(['revisado_por', 'revisado_at']);
        });
    }
};
