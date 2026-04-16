<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LogScript extends Model
{
    use MassPrunable;

    protected $table = 'log_scripts';

    public $timestamps = false;

    /**
     * Prunable query: registros antiguos segun politica de retencion.
     * Complementa a aplicarRetencion(); model:prune los elimina via bulk delete.
     */
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->where(function ($q) {
            $q->where(fn ($s) => $s->where('estado', 'interrumpido')->where('inicio', '<', now()->subDays(7)))
                ->orWhere(fn ($s) => $s->where('estado', 'completado')->where('items_resultado', 0)->where('inicio', '<', now()->subHours(24)))
                ->orWhere(fn ($s) => $s->where('estado', 'error')->where('inicio', '<', now()->subDays(30)))
                ->orWhere(fn ($s) => $s->where('estado', 'completado')->where('items_resultado', '>', 0)->where('inicio', '<', now()->subDays(90)));
        });
    }

    protected $fillable = [
        'script', 'estado', 'inicio', 'fin', 'duracion_segundos',
        'items_procesados', 'items_resultado', 'errores', 'mensaje_error',
    ];

    protected $casts = [
        'inicio' => 'datetime',
        'fin' => 'datetime',
        'duracion_segundos' => 'decimal:2',
    ];

    /**
     * Ultima ejecucion de un script dado.
     */
    public static function ultimaEjecucion(string $script): ?self
    {
        return static::where('script', $script)->latest('inicio')->first();
    }

    /**
     * Indica si un script esta actualmente corriendo.
     * Usa el timeout configurado en config_scripts (default 120 min como fallback).
     */
    public static function estaEjecutando(string $script): bool
    {
        $timeoutMin = \App\Models\ConfigScript::where('script', $script)
            ->value('timeout_minutos') ?? 120;

        return static::where('script', $script)
            ->where('estado', 'iniciado')
            ->whereNull('fin')
            ->where('inicio', '>=', now()->subMinutes($timeoutMin))
            ->exists();
    }

    /**
     * Marca como 'interrumpido' todos los registros iniciados sin fin
     * cuyo inicio supera el timeout configurado.
     */
    public static function limpiarHuerfanos(string $script): int
    {
        $timeoutMin = \App\Models\ConfigScript::where('script', $script)
            ->value('timeout_minutos') ?? 120;

        return static::where('script', $script)
            ->where('estado', 'iniciado')
            ->whereNull('fin')
            ->where('inicio', '<', now()->subMinutes($timeoutMin))
            ->update([
                'estado' => 'interrumpido',
                'fin' => now(),
                'mensaje_error' => 'Proceso interrumpido: no se registró fin dentro del timeout.',
                'duracion_segundos' => DB::raw('EXTRACT(EPOCH FROM (NOW() - inicio))::integer'),
            ]);
    }

    /**
     * Política de retención automática del historial:
     *
     * - Registros 'interrumpido': se eliminan después de 7 días.
     * - Registros 'completado' con 0 resultados: se eliminan después de 24 horas.
     * - Registros 'error': se conservan 30 días.
     * - Registros 'completado' con resultados > 0: se conservan 90 días.
     *
     * Llamar desde Estado::render() o desde runner.py periódicamente.
     */
    public static function aplicarRetencion(): array
    {
        $n1 = static::where('estado', 'interrumpido')
            ->where('inicio', '<', now()->subDays(7))
            ->delete();

        $n2 = static::where('estado', 'completado')
            ->where('items_resultado', 0)
            ->where('inicio', '<', now()->subHours(24))
            ->delete();

        $n3 = static::where('estado', 'error')
            ->where('inicio', '<', now()->subDays(30))
            ->delete();

        $n4 = static::where('estado', 'completado')
            ->where('items_resultado', '>', 0)
            ->where('inicio', '<', now()->subDays(90))
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
