<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds cargo_referenciado to gaceta_eventos_pep.
 *
 * Captures the appointee's PERMANENT role mentioned as context in interim
 * (Pattern B) decrees:
 *   "Desígnese MINISTRO INTERINO DE X, al ciudadano <Name>,
 *    <Cargo Titular>, mientras dure la ausencia del titular."
 *
 * Stored as metadata on the interim event. NULL when not present (permanent
 * appointments, interim decrees without a titular-context clause).
 *
 * Dual-engine compatible (PostgreSQL + SQLite :memory: for tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gaceta_eventos_pep', function (Blueprint $table): void {
            $table->string('cargo_referenciado', 150)
                ->nullable()
                ->after('interino')
                ->comment('Appointee\'s permanent role mentioned in an interim decree as context; null when not present');
        });
    }

    public function down(): void
    {
        Schema::table('gaceta_eventos_pep', function (Blueprint $table): void {
            $table->dropColumn('cargo_referenciado');
        });
    }
};
