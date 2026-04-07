<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigScript extends Model
{
    protected $table = 'config_scripts';

    public $timestamps = false;

    protected $fillable = [
        'script', 'habilitado', 'intervalo_minutos',
        'hora_inicio', 'hora_fin', 'dias_semana',
        'timeout_minutos', 'notas',
    ];

    protected $casts = [
        'habilitado' => 'boolean',
        'intervalo_minutos' => 'integer',
        'timeout_minutos' => 'integer',
    ];

    /**
     * Obtener config de un script, creando defaults si no existe.
     */
    public static function para(string $script): self
    {
        return static::firstOrCreate(
            ['script' => $script],
            [
                'habilitado' => true,
                'intervalo_minutos' => $script === 'scraper' ? 60 : 300,
                'timeout_minutos' => 120,
                'dias_semana' => '1,2,3,4,5,6,7',
            ]
        );
    }

    /**
     * Array legible de dias activos.
     */
    public function diasArray(): array
    {
        return array_map('intval', explode(',', $this->dias_semana));
    }

    /**
     * Etiqueta del intervalo para mostrar en UI.
     */
    public function intervaloLabel(): string
    {
        $min = $this->intervalo_minutos;
        if ($min < 60) {
            return "{$min} minutos";
        }
        $h = intdiv($min, 60);
        $m = $min % 60;

        return $m > 0 ? "{$h}h {$m}min" : ($h === 1 ? '1 hora' : "{$h} horas");
    }
}
