<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogEjecucion extends Model
{
    protected $table = 'log_ejecuciones';
    public $timestamps = false;

    protected $fillable = [
        'fecha_ejecucion', 'sitios_procesados', 'resultados_encontrados',
        'errores', 'duracion_segundos',
    ];

    protected $casts = [
        'fecha_ejecucion' => 'datetime',
        'duracion_segundos' => 'decimal:2',
    ];
}
