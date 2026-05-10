<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M4: Seed dedupe configuration row in config_scripts.
 *
 * Design D5: Reuse config_scripts to avoid migration overhead of a new table.
 *
 * PRAGMA: 'dedupe' row reuses config_scripts to store DedupeArticulosJob config.
 * Column semantics are intentionally repurposed:
 *   - intervalo_minutos → ventana_dias (integer, default 7 = 7-day window)
 *   - notas            → JSON payload with threshold (0.90) and metadata
 *   - habilitado       → kill-switch: false disables DedupeArticulosJob dispatch
 *
 * This is pragmatic abuse of the table — 1 row, no new schema overhead.
 * See ConfigScript::dedupeThreshold() and ConfigScript::ventanaDias() helpers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PRAGMA: dedupe is conceptually different from a Python script but reuses
        // config_scripts to avoid migration overhead for a single-row config need.
        // intervalo_minutos is repurposed as ventana_dias (the look-back window for
        // similarity deduplication). This is documented in ConfigScript model helpers.
        DB::table('config_scripts')->updateOrInsert(
            ['script' => 'dedupe'],
            [
                'habilitado'         => true,
                'intervalo_minutos'  => 7,   // ventana_dias: look-back window in days
                'timeout_minutos'    => 5,
                'dias_semana'        => '1,2,3,4,5,6,7',
                'notas'              => json_encode([
                    'threshold'    => 0.90,
                    'ventana_dias' => 7,
                    'comment'      => 'PRAGMA: dedupe is conceptually different from a Python script but reuses config_scripts to avoid migration overhead',
                ]),
                'actualizado_en'     => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('config_scripts')->where('script', 'dedupe')->delete();
    }
};
