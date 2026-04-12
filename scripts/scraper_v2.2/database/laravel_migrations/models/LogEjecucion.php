<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LogEjecucion extends Model
{
    protected $table = 'log_ejecuciones';

    // El scraper Python gestiona fecha_ejecucion; sin timestamps de Laravel
    public $timestamps = false;

    protected $fillable = [
        'fecha_ejecucion',
        'sitios_procesados',
        'resultados_encontrados',
        'errores',
        'duracion_segundos',
    ];

    protected $casts = [
        'fecha_ejecucion' => 'datetime',
        'sitios_procesados' => 'integer',
        'resultados_encontrados' => 'integer',
        'errores' => 'integer',
        'duracion_segundos' => 'decimal:2',
    ];

    // ── Scopes ────────────────────────────────────────────────

    public function scopeRecientes(Builder $query, int $dias = 7): Builder
    {
        return $query->where('fecha_ejecucion', '>=', now()->subDays($dias))
            ->orderByDesc('fecha_ejecucion');
    }

    public function scopeConErrores(Builder $query): Builder
    {
        return $query->where('errores', '>', 0);
    }

    // ── Helpers ───────────────────────────────────────────────

    public function getDuracionFormateadaAttribute(): string
    {
        $seg = (int) $this->duracion_segundos;
        if ($seg < 60) {
            return "{$seg}s";
        }
        if ($seg < 3600) {
            return floor($seg / 60).'m '.($seg % 60).'s';
        }

        return floor($seg / 3600).'h '.floor(($seg % 3600) / 60).'m';
    }

    public function getTieneErroresAttribute(): bool
    {
        return $this->errores > 0;
    }
}
