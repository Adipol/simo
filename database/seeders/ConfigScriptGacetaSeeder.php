<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ConfigScript;
use Illuminate\Database\Seeder;

/**
 * Seeds the config_scripts row for the gaceta collector.
 *
 * Idempotent via updateOrCreate — safe to run multiple times.
 * The 'gaceta' script value requires migration 2026_06_14_000003
 * (add_gaceta_to_log_scripts_script) to have run first.
 */
class ConfigScriptGacetaSeeder extends Seeder
{
    public function run(): void
    {
        ConfigScript::updateOrCreate(
            ['script' => 'gaceta'],
            [
                'habilitado'        => true,
                'intervalo_minutos' => 60,
                'hora_inicio'       => null,
                'hora_fin'          => null,
                'dias_semana'       => '1,2,3,4,5,6,7',
                'timeout_minutos'   => 30,
                'notas'             => 'Gaceta Oficial collector. Polls listadonor/11 for Decreto Presidencial entries. Runs hourly.',
            ]
        );
    }
}
