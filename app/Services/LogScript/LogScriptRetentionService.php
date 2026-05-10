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
     * Aplica la política de retención automática del historial de scripts:
     *
     * - Registros 'interrumpido': se eliminan después de 7 días.
     * - Registros 'completado' con 0 resultados: se eliminan después de 24 horas.
     * - Registros 'error': se conservan 30 días.
     * - Registros 'completado' con resultados > 0: se conservan 90 días.
     *
     * @return array{interrumpidos: int, completados_vacios: int, errores_viejos: int, completados_viejos: int, total: int}
     */
    public function aplicarRetencion(): array
    {
        $n1 = LogScript::where('estado', 'interrumpido')
            ->where('inicio', '<', now()->subDays(7))
            ->delete();

        $n2 = LogScript::where('estado', 'completado')
            ->where('items_resultado', 0)
            ->where('inicio', '<', now()->subHours(24))
            ->delete();

        $n3 = LogScript::where('estado', 'error')
            ->where('inicio', '<', now()->subDays(30))
            ->delete();

        $n4 = LogScript::where('estado', 'completado')
            ->where('items_resultado', '>', 0)
            ->where('inicio', '<', now()->subDays(90))
            ->delete();

        return [
            'interrumpidos'     => $n1,
            'completados_vacios' => $n2,
            'errores_viejos'    => $n3,
            'completados_viejos' => $n4,
            'total'             => $n1 + $n2 + $n3 + $n4,
        ];
    }
}
