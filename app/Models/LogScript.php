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
                'duracion_segundos' => DB::raw(self::epochSecondsSince('inicio')),
            ]);
    }

    /**
     * Returns a driver-aware SQL expression for elapsed seconds since $column.
     *
     * Mirrors DashboardSummaryService::dateTruncDay() pattern: returns a raw
     * string fragment; caller wraps with DB::raw() or whereRaw().
     *
     * SQLite note: datetimes are stored in the app timezone (America/La_Paz = UTC-4).
     * julianday('now') is UTC, so we apply the inverse offset to the stored column
     * to convert it to UTC before computing the difference.
     */
    private static function epochSecondsSince(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "EXTRACT(EPOCH FROM (NOW() - {$column}))::integer",
            'sqlite' => self::sqliteEpochSecondsSince($column),
            default => throw new \RuntimeException('Unsupported DB driver: '.DB::getDriverName()),
        };
    }

    /**
     * SQLite-specific epoch-seconds expression with timezone correction.
     *
     * Stored datetimes are in the app timezone (e.g. America/La_Paz = UTC-4).
     * SQLite's julianday('now') is always UTC. We shift the stored column by the
     * inverse of the PHP timezone offset so both sides use the same reference.
     */
    private static function sqliteEpochSecondsSince(string $column): string
    {
        $offsetHours = (int) round(-now()->utcOffset() / 60);
        $modifier = sprintf('%+d hours', $offsetHours);

        return "CAST((julianday('now') - julianday({$column}, '{$modifier}')) * 86400 AS INTEGER)";
    }

    /**
     * @deprecated Delegado a LogScriptRetentionService::aplicarRetencion()
     * Mantenido por compatibilidad con código existente (LimpiarLogs command).
     *
     * @return array{interrumpidos: int, completados_vacios: int, errores_viejos: int, completados_viejos: int, total: int}
     */
    public static function aplicarRetencion(): array
    {
        return (new LogScriptRetentionService)->aplicarRetencion();
    }
}
