<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Limpieza diaria de log_scripts: huerfanos + politica de retencion
Schedule::command('simo:limpiar-logs')->dailyAt('03:00');

// Poda de modelos con Prunable (LogScript si se activa el trait)
Schedule::command('model:prune')->dailyAt('03:30');

// Gemini: dispatch jobs para registros pendientes (safety net para Python)
Schedule::command('simo:analizar-gemini')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Dedupe: safety net para filas sin procesar (dedupe_processed_at IS NULL)
Schedule::command('simo:dedupar-pendientes')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Limpieza de imágenes de cambios: borra archivos de cambios inexistentes o >90 días
Schedule::command('cleanup:imagenes-cambios --days=90')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();
