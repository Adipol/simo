<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogScript extends Model
{
    protected $table = 'log_scripts';
    public $timestamps = false;

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
     */
    public static function estaEjecutando(string $script): bool
    {
        return static::where('script', $script)
            ->where('estado', 'iniciado')
            ->where('inicio', '>=', now()->subHours(2)) // timeout seguridad: 2h
            ->exists();
    }
}
