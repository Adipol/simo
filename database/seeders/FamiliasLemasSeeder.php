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
            ['raiz' => 'designar', 'variantes' => ['designar', 'designación', 'designaciones', 'designado', 'designada'], 'categoria' => 'designacion'],
            ['raiz' => 'nombrar', 'variantes' => ['nombrar', 'nombramiento', 'nombramientos', 'nombrado', 'nombrada'], 'categoria' => 'designacion'],
            ['raiz' => 'posesionar', 'variantes' => ['posesionar', 'posesión', 'posesionado', 'posesionada'], 'categoria' => 'designacion'],
            ['raiz' => 'asumir', 'variantes' => ['asumir', 'asunción', 'asumió'], 'categoria' => 'designacion'],
            ['raiz' => 'juramentar', 'variantes' => ['juramentar', 'juramento', 'juramentado', 'juramentada'], 'categoria' => 'designacion'],
            ['raiz' => 'elegir', 'variantes' => ['elegir', 'elección', 'elecciones', 'elegido', 'elegida'], 'categoria' => 'designacion'],
            ['raiz' => 'ratificar', 'variantes' => ['ratificar', 'ratificación', 'ratificado', 'ratificada'], 'categoria' => 'designacion'],
            ['raiz' => 'confirmar', 'variantes' => ['confirmar', 'confirmación', 'confirmado', 'confirmada'], 'categoria' => 'designacion'],
            ['raiz' => 'incorporar', 'variantes' => ['incorporar', 'incorporación', 'incorporado', 'incorporada'], 'categoria' => 'designacion'],

            // ── RENUNCIA (8) ──────────────────────────────────────────────────
            ['raiz' => 'renunciar', 'variantes' => ['renunciar', 'renuncia', 'renuncias', 'renunciado', 'renunció'], 'categoria' => 'renuncia'],
            ['raiz' => 'destituir', 'variantes' => ['destituir', 'destitución', 'destituciones', 'destituido', 'destituida'], 'categoria' => 'renuncia'],
            ['raiz' => 'cesar', 'variantes' => ['cesar', 'cese', 'ceses', 'cesado', 'cesada'], 'categoria' => 'renuncia'],
            ['raiz' => 'remover', 'variantes' => ['remover', 'remoción', 'removido', 'removida'], 'categoria' => 'renuncia'],
            ['raiz' => 'reemplazar', 'variantes' => ['reemplazar', 'reemplazo', 'reemplazado', 'reemplazada'], 'categoria' => 'renuncia'],
            ['raiz' => 'sustituir', 'variantes' => ['sustituir', 'sustitución', 'sustituido', 'sustituida'], 'categoria' => 'renuncia'],
            ['raiz' => 'dejar', 'variantes' => ['dejar el cargo', 'deja el cargo', 'dejó el cargo'], 'categoria' => 'renuncia'],
            ['raiz' => 'suceder', 'variantes' => ['suceder', 'sucesión', 'sucesor', 'sucesora'], 'categoria' => 'renuncia'],

            // ── CRIMEN (16) ───────────────────────────────────────────────────
            ['raiz' => 'detener', 'variantes' => ['detener', 'detención', 'detenciones', 'detenido', 'detenida'], 'categoria' => 'crimen'],
            ['raiz' => 'imputar', 'variantes' => ['imputar', 'imputación', 'imputaciones', 'imputado', 'imputada'], 'categoria' => 'crimen'],
            ['raiz' => 'acusar', 'variantes' => ['acusar', 'acusación', 'acusado', 'acusada'], 'categoria' => 'crimen'],
            ['raiz' => 'procesar', 'variantes' => ['procesar', 'proceso', 'procesado', 'procesada'], 'categoria' => 'crimen'],
            ['raiz' => 'investigar', 'variantes' => ['investigar', 'investigación', 'investigaciones', 'investigado', 'investigada'], 'categoria' => 'crimen'],
            ['raiz' => 'allanar', 'variantes' => ['allanar', 'allanamiento', 'allanamientos'], 'categoria' => 'crimen'],
            ['raiz' => 'estafar', 'variantes' => ['estafar', 'estafa', 'estafador', 'estafadores'], 'categoria' => 'crimen'],
            ['raiz' => 'corromper', 'variantes' => ['corromper', 'corrupción', 'corrupto', 'corrupta'], 'categoria' => 'crimen'],
            ['raiz' => 'fraudar', 'variantes' => ['fraude', 'fraudulento', 'fraudulenta'], 'categoria' => 'crimen'],
            ['raiz' => 'lavar', 'variantes' => ['lavado', 'lavado de activos', 'lavado de dinero'], 'categoria' => 'crimen'],
            ['raiz' => 'malversar', 'variantes' => ['malversar', 'malversación', 'malversado'], 'categoria' => 'crimen'],
            ['raiz' => 'narcotraficar', 'variantes' => ['narcotráfico', 'narcotraficante', 'narcotraficantes'], 'categoria' => 'crimen'],
            ['raiz' => 'traficar', 'variantes' => ['traficar', 'tráfico', 'traficante', 'traficantes'], 'categoria' => 'crimen'],
            ['raiz' => 'asesinar', 'variantes' => ['asesinar', 'asesinato', 'asesino', 'asesina'], 'categoria' => 'crimen'],
            ['raiz' => 'secuestrar', 'variantes' => ['secuestrar', 'secuestro', 'secuestrado', 'secuestrada'], 'categoria' => 'crimen'],
            ['raiz' => 'extorsionar', 'variantes' => ['extorsionar', 'extorsión', 'extorsionador', 'extorsionadores'], 'categoria' => 'crimen'],
        ];

        foreach ($familias as $familia) {
            FamiliaLema::updateOrCreate(
                ['raiz' => $familia['raiz']],
                $familia,
            );
        }
    }
}
