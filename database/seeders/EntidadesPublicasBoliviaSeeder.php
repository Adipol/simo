<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EntidadesPublicasBoliviaSeeder extends Seeder
{
    public function run(): void
    {
        $entidades = [
            // ── Empresas Estatales ──────────────────────────────────────────
            ['nombre' => 'YPFB',          'sigla' => 'YPFB'],
            ['nombre' => 'ENDE',          'sigla' => 'ENDE'],
            ['nombre' => 'ENTEL',         'sigla' => 'ENTEL'],
            ['nombre' => 'Banco Unión',   'sigla' => null],
            ['nombre' => 'BoA',           'sigla' => 'BoA'],
            ['nombre' => 'EBA',           'sigla' => 'EBA'],
            ['nombre' => 'COMIBOL',       'sigla' => 'COMIBOL'],
            ['nombre' => 'Vinto',         'sigla' => null],
            ['nombre' => 'Huanuni',       'sigla' => null],
            ['nombre' => 'Papelbol',      'sigla' => null],

            // ── Entidades Financieras Públicas ───────────────────────────────
            ['nombre' => 'Banco Central de Bolivia',      'sigla' => 'BCB'],
            ['nombre' => 'Banco de Desarrollo Productivo', 'sigla' => 'BDP'],
            ['nombre' => 'FONDESIF',                      'sigla' => 'FONDESIF'],

            // ── Universidades Públicas ───────────────────────────────────────
            ['nombre' => 'UMSA',  'sigla' => 'UMSA'],
            ['nombre' => 'UMSS',  'sigla' => 'UMSS'],
            ['nombre' => 'UAGRM', 'sigla' => 'UAGRM'],
            ['nombre' => 'UATF',  'sigla' => 'UATF'],
            ['nombre' => 'UTO',   'sigla' => 'UTO'],
            ['nombre' => 'UAB',   'sigla' => 'UAB'],
            ['nombre' => 'UAP',   'sigla' => 'UAP'],
            ['nombre' => 'USFX',  'sigla' => 'USFX'],
            ['nombre' => 'UPEA',  'sigla' => 'UPEA'],

            // ── Entes Reguladores ────────────────────────────────────────────
            ['nombre' => 'ASFI',  'sigla' => 'ASFI'],
            ['nombre' => 'ATT',   'sigla' => 'ATT'],
            ['nombre' => 'AE',    'sigla' => 'AE'],
            ['nombre' => 'ANH',   'sigla' => 'ANH'],
            ['nombre' => 'AEMP',  'sigla' => 'AEMP'],
            ['nombre' => 'APS',   'sigla' => 'APS'],

            // ── Servicios Estatales ──────────────────────────────────────────
            ['nombre' => 'SENASAG', 'sigla' => 'SENASAG'],
            ['nombre' => 'SIN',     'sigla' => 'SIN'],
            ['nombre' => 'SEGIP',   'sigla' => 'SEGIP'],
            ['nombre' => 'ABC',     'sigla' => 'ABC'],
            ['nombre' => 'SERECI',  'sigla' => 'SERECI'],
        ];

        foreach ($entidades as $entidad) {
            DB::table('entidades_publicas')->updateOrInsert(
                ['pais_codigo' => 'BO', 'nombre' => $entidad['nombre']],
                array_merge($entidad, [
                    'pais_codigo' => 'BO',
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
