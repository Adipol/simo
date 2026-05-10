<?php

declare(strict_types=1);

namespace App\Services\LogScript;

use App\Models\LogScript;

/**
 * Handles retention policy for LogScript records.
 *
 * Extracted from the model per project convention:
 * "Logica de negocio en Services (app/Services/)".
 */
final class LogScriptRetentionService
{
    /**
     * Aplica la política de retención automática del historial de scripts.
     *
     * Los períodos de retención se leen de config/logscript.php:
     * - 'interrumpido': retention_interrumpido_days (default 7)
     * - 'completado' con 0 resultados: retention_completado_vacio_hours (default 24)
     * - 'error': retention_error_days (default 30)
     * - 'completado' con resultados > 0: retention_completado_dias (default 90)
     *
     * @return array{interrumpidos: int, completados_vacios: int, errores_viejos: int, completados_viejos: int, total: int}
     */
    public function aplicarRetencion(): array
    {
        $diasInterrumpido = (int) config('logscript.retention_interrumpido_days', 7);
        $horasCompletadoVacio = (int) config('logscript.retention_completado_vacio_hours', 24);
        $diasError = (int) config('logscript.retention_error_days', 30);
        $diasCompletado = (int) config('logscript.retention_completado_dias', 90);

        $n1 = LogScript::where('estado', 'interrumpido')
            ->where('inicio', '<', now()->subDays($diasInterrumpido))
            ->delete();

        $n2 = LogScript::where('estado', 'completado')
            ->where('items_resultado', 0)
            ->where('inicio', '<', now()->subHours($horasCompletadoVacio))
            ->delete();

        $n3 = LogScript::where('estado', 'error')
            ->where('inicio', '<', now()->subDays($diasError))
            ->delete();

        $n4 = LogScript::where('estado', 'completado')
            ->where('items_resultado', '>', 0)
            ->where('inicio', '<', now()->subDays($diasCompletado))
            ->delete();

        return [
            'interrumpidos' => $n1,
            'completados_vacios' => $n2,
            'errores_viejos' => $n3,
            'completados_viejos' => $n4,
            'total' => $n1 + $n2 + $n3 + $n4,
        ];
    }
}
