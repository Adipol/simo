<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\LogScript\LogScriptRetentionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LogScript extends Model
{
    use HasFactory, MassPrunable;

    protected $table = 'log_scripts';

    public $timestamps = false;

    /**
     * Prunable query: registros antiguos segun politica de retencion.
     * Complementa a LogScriptRetentionService; model:prune los elimina via bulk delete.
     */
    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->where(function ($q): void {
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
        $timeoutMin = ConfigScript::where('script', $script)
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
        $timeoutMin = ConfigScript::where('script', $script)
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
     * @deprecated Delegado a LogScriptRetentionService::aplicarRetencion()
     * Mantenido por compatibilidad con código existente (LimpiarLogs command).
     *
     * @return array{interrumpidos: int, completados_vacios: int, errores_viejos: int, completados_viejos: int, total: int}
     */
    public static function aplicarRetencion(): array
    {
        return (new LogScriptRetentionService())->aplicarRetencion();
    }
}
