<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CargosPepBoliviaSeeder extends Seeder
{
    public function run(): void
    {
        $cargos = [
            // ── Grupo: todas (7) ─────────────────────────────────────────────
            ['nombre' => 'Diputado Titular y Suplente',                              'categoria' => 'Legislativo', 'entidad_tipo' => 'todas'],
            ['nombre' => 'Senador Titular y Suplente',                               'categoria' => 'Legislativo', 'entidad_tipo' => 'todas'],
            ['nombre' => 'Asambleísta Departamental',                                'categoria' => 'Legislativo', 'entidad_tipo' => 'todas'],
            ['nombre' => 'Consejo Municipal',                                        'categoria' => 'Legislativo', 'entidad_tipo' => 'todas'],
            ['nombre' => 'Asambleísta (Asamblea General de Comunidades)',             'categoria' => 'Legislativo', 'entidad_tipo' => 'todas'],
            ['nombre' => 'Asambleísta Regional',                                     'categoria' => 'Legislativo', 'entidad_tipo' => 'todas'],
            ['nombre' => 'Dirigentes de Partidos Políticos',                         'categoria' => 'Autónomo',    'entidad_tipo' => 'todas'],

            // ── Grupo: ambas (15) ────────────────────────────────────────────
            ['nombre' => 'Miembros del Directorio (Entidades Autárquicas)',          'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Presidencia Ejecutiva (Entidades Autárquicas)',             'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Director (Entidades Desconcentradas)',                      'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Director General Ejecutivo (Entidades Descentralizadas)',   'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Director (Entidades Descentralizadas)',                     'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Director General (Empresas Públicas)',                      'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Gerente (Empresas Públicas)',                               'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Miembros del Directorio (Empresas Públicas)',               'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Presidente o Presidente Ejecutivo (Empresas Públicas)',     'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Vicepresidente (Empresas Públicas)',                        'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Rector (Universidades Públicas)',                           'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Vicerrector (Universidades Públicas)',                      'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Decanos (Universidades Públicas)',                          'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Rector (Institutos Públicos)',                              'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],
            ['nombre' => 'Vicerrector (Institutos Públicos)',                         'categoria' => 'Autónomo',    'entidad_tipo' => 'ambas'],

            // ── Grupo: publica — Ejecutivo (11) ─────────────────────────────
            ['nombre' => 'Presidente del Estado',                                    'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Vicepresidente del Estado',                                'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Secretario General del Órgano Ejecutivo',                  'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Ministro de Estado',                                       'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Viceministro de Estado',                                   'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Procurador General del Estado',                            'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Subprocurador General del Estado',                         'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Contralor General del Estado',                             'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Subcontralor General del Estado',                          'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Defensor del Pueblo',                                      'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Delegado Adjunto de la Defensoría del Pueblo',             'categoria' => 'Ejecutivo',   'entidad_tipo' => 'publica'],

            // ── Grupo: publica — Legislativo (1) ────────────────────────────
            ['nombre' => 'Oficial Mayor del Órgano Legislativo',                     'categoria' => 'Legislativo', 'entidad_tipo' => 'publica'],

            // ── Grupo: publica — Judicial (13) ──────────────────────────────
            ['nombre' => 'Magistrado Titular y Suplente del TSJ',                    'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Presidente del TSJ',                                       'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Magistrado Titular y Suplente del TAJ',                    'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Presidente del TAJ',                                       'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Presidente del Consejo de la Magistratura',                'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Consejero del Consejo de la Magistratura',                 'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Director de la Dirección Administrativa Financiera del Órgano Judicial', 'categoria' => 'Judicial', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Presidente del Tribunal Constitucional Plurinacional',     'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Magistrado del Tribunal Constitucional Plurinacional',     'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Fiscal General del Estado',                                'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Fiscal Departamental',                                     'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Fiscal Superior',                                          'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Director Nacional del Ministerio Público',                 'categoria' => 'Judicial',    'entidad_tipo' => 'publica'],

            // ── Grupo: publica — Militar (26) ────────────────────────────────
            ['nombre' => 'Comandante en Jefe de las Fuerzas Armadas',                'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Jefe de Estado Mayor General de las Fuerzas Armadas',      'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante General del Ejército',                          'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante General de la Fuerza Aérea',                   'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante General de la Armada Boliviana',               'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Inspector General de las Fuerzas Armadas',                'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante General de la Policía Boliviana',              'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Subcomandante General de la Policía Boliviana',           'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Inspector General de la Policía Boliviana',               'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Presidente del Tribunal Disciplinario Superior de la Policía', 'categoria' => 'Militar', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Director Nacional de Personal de la Policía',              'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Director Nacional de Inteligencia de la Policía',          'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Director Nacional de Planeamiento y Operaciones de la Policía', 'categoria' => 'Militar', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Director Nacional de Instrucción y Enseñanza de la Policía', 'categoria' => 'Militar',   'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía La Paz',               'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Oruro',                'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Potosí',               'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Chuquisaca',           'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Cochabamba',           'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Tarija',               'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Santa Cruz',           'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Beni',                 'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Comandante Departamental de Policía Pando',                'categoria' => 'Militar',     'entidad_tipo' => 'publica'],
            ['nombre' => 'Director General de la Fuerza Especial de Lucha contra la Violencia (FELCV)',   'categoria' => 'Militar', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Director General de la Fuerza Especial de Lucha contra el Crimen (FELCC)',      'categoria' => 'Militar', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Director General de la Fuerza Especial de Lucha contra el Narcotráfico (FELCN)', 'categoria' => 'Militar', 'entidad_tipo' => 'publica'],

            // ── Grupo: publica — Diplomático (9) ────────────────────────────
            ['nombre' => 'Secretario General de Representante Internacional',        'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Director General de Representante Internacional',          'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Director Adjunto de Representante Internacional',          'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Embajador',                                                'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Cónsul',                                                   'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Jefe de Estado o de Gobierno (extranjero)',                'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Funcionario Gubernamental o Judicial de Alto Nivel (extranjero)', 'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Ejecutivo de Alto Nivel de Corporación Estatal (extranjero)', 'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Representante Permanente ante Organismos Internacionales', 'categoria' => 'Diplomático', 'entidad_tipo' => 'publica'],

            // ── Grupo: publica — Autónomo (15) ──────────────────────────────
            ['nombre' => 'Presidente del Tribunal Supremo Electoral',                'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Vocal Titular y Suplente del Tribunal Supremo Electoral',  'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Presidente de Tribunal Electoral Departamental',            'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Vocal Titular y Suplente de Tribunal Electoral Departamental', 'categoria' => 'Autónomo', 'entidad_tipo' => 'publica'],
            ['nombre' => 'Gobernador',                                               'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Vicegobernador',                                           'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Secretario Departamental',                                 'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Oficial Mayor Departamental',                              'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Alcalde',                                                  'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Secretario Municipal',                                     'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Subalcalde',                                               'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Autoridad Administrativa Autonómica Indígena',             'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Miembro del Consejo de Gestión Territorial Indígena',      'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Ejecutivo Regional',                                       'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
            ['nombre' => 'Secretario Municipal Regional',                            'categoria' => 'Autónomo',    'entidad_tipo' => 'publica'],
        ];

        foreach ($cargos as $cargo) {
            DB::table('cargos_pep')->updateOrInsert(
                ['pais_codigo' => 'BO', 'nombre' => $cargo['nombre']],
                array_merge($cargo, [
                    'pais_codigo' => 'BO',
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
