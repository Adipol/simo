<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatosInicialesSeeder extends Seeder
{
    public function run(): void
    {
        // ── Sitios web ────────────────────────────────────────────
        $sitios = [
            // Bolivia
            ['url' => 'https://eju.tv',                                    'nombre' => 'EJU TV',            'pais' => 'BO'],
            ['url' => 'https://eldeber.com.bo',                            'nombre' => 'EL DEBER',          'pais' => 'BO'],
            ['url' => 'https://www.eldiario.net',                          'nombre' => 'EL DIARIO',         'pais' => 'BO'],
            ['url' => 'https://www.abi.bo/',                               'nombre' => 'ABI NOTICIAS',      'pais' => 'BO'],
            ['url' => 'https://www.noticiasfides.com/',                    'nombre' => 'NOTICIAS FIDES',    'pais' => 'BO'],
            ['url' => 'https://www.opinion.com.bo',                        'nombre' => 'OPINION',           'pais' => 'BO'],
            ['url' => 'https://elpais.bo/',                                'nombre' => 'EL PAIS',           'pais' => 'BO'],
            ['url' => 'https://www.reduno.com.bo/',                        'nombre' => 'RED UNO',           'pais' => 'BO'],
            ['url' => 'https://www.economiayfinanzas.gob.bo/node/',          'nombre' => 'FINANZAS',          'pais' => 'BO'],
            ['url' => 'https://www.erbol.com.bo/',                         'nombre' => 'ERBOL',             'pais' => 'BO'],
            // Honduras
            ['url' => 'https://www.latribuna.hn/',                         'nombre' => 'LA TRIBUNA',        'pais' => 'HN'],
            ['url' => 'https://www.lapatria.com',                          'nombre' => 'LA PATRIA',         'pais' => 'HN'],
            ['url' => 'https://www.elheraldo.hn/',                         'nombre' => 'EL HERALDO',        'pais' => 'HN'],
            ['url' => 'https://proceso.hn/',                               'nombre' => 'PROCESO DIGITAL',   'pais' => 'HN'],
            ['url' => 'https://tiempo.hn/',                                'nombre' => 'TIEMPO',            'pais' => 'HN'],
        ];

        foreach ($sitios as $sitio) {
            DB::table('sitios_web')->updateOrInsert(
                ['url' => $sitio['url']],
                array_merge($sitio, [
                    'activo' => true,
                    'selector_links' => null,
                    'selector_article' => null,
                    'fecha_creacion' => now(),
                    'fecha_modificacion' => now(),
                ])
            );
        }

        $this->command->info('Sitios web insertados: '.count($sitios));

        // ── Palabras clave ────────────────────────────────────────
        $keywords = [
            'Juramento', 'designado', 'nombramiento', 'posesionado', 'jura',
            'electo', 'ratificado', 'ministro', 'viceministro', 'magistrado',
            'gobernador', 'fiscal', 'rector', 'vicerrector', 'decanos',
            'vicegobernador', 'asambleista', 'alcalde', 'subalcalde', 'comandante',
            'subcomandante', 'procurador', 'subprocurador', 'contralor', 'subcontralor',
            'embajador', 'cónsul', 'designase', 'posesionan', 'posesionarán',
        ];

        foreach ($keywords as $kw) {
            DB::table('palabras_clave')->updateOrInsert(
                ['keyword' => $kw],
                [
                    'keyword' => $kw,
                    'categoria' => 'PEP',
                    'activo' => true,
                    'fecha_creacion' => now(),
                ]
            );
        }

        $this->command->info('Palabras clave insertadas: '.count($keywords));
    }
}
