<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FamiliaLema;
use Illuminate\Database\Seeder;

class FamiliasLemasSeeder extends Seeder
{
    public function run(): void
    {
        $familias = [
            // ── DESIGNACION (9) ───────────────────────────────────────────────
            // Variantes genéricas (infinitivos, sustantivos comunes) eliminadas
            // para reducir ruido. Solo se mantienen participios y formas específicas.
            ['raiz' => 'designar', 'variantes' => ['designar', 'designación', 'designaciones', 'designado', 'designada'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'nombrar', 'variantes' => ['nombrar', 'nombramiento', 'nombramientos', 'nombrado', 'nombrada'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'posesionar', 'variantes' => ['posesionar', 'posesión', 'posesionado', 'posesionada'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'asumir', 'variantes' => ['asumió', 'asunción'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'juramentar', 'variantes' => ['juramentar', 'juramento', 'juramentado', 'juramentada'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'elegir', 'variantes' => ['elegido', 'elegida'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'ratificar', 'variantes' => ['ratificar', 'ratificación', 'ratificado', 'ratificada'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'confirmar', 'variantes' => ['confirmado', 'confirmada'], 'categoria' => 'PEP-designacion'],
            ['raiz' => 'incorporar', 'variantes' => ['incorporado', 'incorporada'], 'categoria' => 'PEP-designacion'],

            // ── RENUNCIA (8) ──────────────────────────────────────────────────
            ['raiz' => 'renunciar', 'variantes' => ['renunciar', 'renuncia', 'renuncias', 'renunciado', 'renunció'], 'categoria' => 'PEP-renuncia'],
            ['raiz' => 'destituir', 'variantes' => ['destituir', 'destitución', 'destituciones', 'destituido', 'destituida'], 'categoria' => 'PEP-renuncia'],
            ['raiz' => 'cesar', 'variantes' => ['cesar', 'cese', 'ceses', 'cesado', 'cesada'], 'categoria' => 'PEP-renuncia'],
            ['raiz' => 'remover', 'variantes' => ['remover', 'remoción', 'removido', 'removida'], 'categoria' => 'PEP-renuncia'],
            ['raiz' => 'reemplazar', 'variantes' => ['reemplazar', 'reemplazo', 'reemplazado', 'reemplazada'], 'categoria' => 'PEP-renuncia'],
            ['raiz' => 'sustituir', 'variantes' => ['sustituir', 'sustitución', 'sustituido', 'sustituida'], 'categoria' => 'PEP-renuncia'],
            ['raiz' => 'dejar', 'variantes' => ['dejar el cargo', 'deja el cargo', 'dejó el cargo'], 'categoria' => 'PEP-renuncia'],
            ['raiz' => 'suceder', 'variantes' => ['sucesor', 'sucesora', 'sucesión'], 'categoria' => 'PEP-renuncia'],

            // ── CRIMEN (16) ───────────────────────────────────────────────────
            ['raiz' => 'detener', 'variantes' => ['detener', 'detención', 'detenciones', 'detenido', 'detenida'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'imputar', 'variantes' => ['imputar', 'imputación', 'imputaciones', 'imputado', 'imputada'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'acusar', 'variantes' => ['acusar', 'acusación', 'acusado', 'acusada'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'procesar', 'variantes' => ['procesado', 'procesada'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'investigar', 'variantes' => ['investigado', 'investigada'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'allanar', 'variantes' => ['allanar', 'allanamiento', 'allanamientos'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'estafar', 'variantes' => ['estafar', 'estafa', 'estafador', 'estafadores'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'corromper', 'variantes' => ['corromper', 'corrupción', 'corrupto', 'corrupta'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'fraudar', 'variantes' => ['fraude', 'fraudulento', 'fraudulenta'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'lavar', 'variantes' => ['lavado de activos', 'lavado de dinero'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'malversar', 'variantes' => ['malversar', 'malversación', 'malversado'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'narcotraficar', 'variantes' => ['narcotráfico', 'narcotraficante', 'narcotraficantes'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'traficar', 'variantes' => ['traficante', 'traficantes'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'asesinar', 'variantes' => ['asesinar', 'asesinato', 'asesino', 'asesina'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'secuestrar', 'variantes' => ['secuestrar', 'secuestro', 'secuestrado', 'secuestrada'], 'categoria' => 'OPI-crimen'],
            ['raiz' => 'extorsionar', 'variantes' => ['extorsionar', 'extorsión', 'extorsionador', 'extorsionadores'], 'categoria' => 'OPI-crimen'],
        ];

        foreach ($familias as $familia) {
            FamiliaLema::updateOrCreate(
                ['raiz' => $familia['raiz']],
                $familia,
            );
        }
    }
}
