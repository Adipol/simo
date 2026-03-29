<?php

namespace Database\Seeders;

use App\Models\ConfigScript;
use Illuminate\Database\Seeder;

class ConfigScriptsSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            [
                'script'            => 'scraper',
                'habilitado'        => true,
                'intervalo_minutos' => 60,
                'hora_inicio'       => '06:00:00',
                'hora_fin'          => '23:00:00',
                'dias_semana'       => '1,2,3,4,5,6,7',
                'timeout_minutos'   => 120,
                'notas'             => 'Scraping de sitios web. Ejecuta cada hora entre 06:00 y 23:00.',
            ],
            [
                'script'            => 'pep_monitor',
                'habilitado'        => true,
                'intervalo_minutos' => 300,
                'hora_inicio'       => null,
                'hora_fin'          => null,
                'dias_semana'       => '1,2,3,4,5',
                'timeout_minutos'   => 60,
                'notas'             => 'Monitor PEP. Cada 5 horas de lunes a viernes.',
            ],
        ];

        foreach ($configs as $data) {
            ConfigScript::updateOrCreate(['script' => $data['script']], $data);
        }
    }
}
