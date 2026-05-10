<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LogScript;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogScript>
 */
class LogScriptFactory extends Factory
{
    protected $model = LogScript::class;

    public function definition(): array
    {
        $inicio = now()->subMinutes($this->faker->numberBetween(5, 60));
        $fin = (clone $inicio)->addSeconds($this->faker->numberBetween(10, 300));

        return [
            'script'             => $this->faker->randomElement(['scraper', 'pep_monitor']),
            'estado'             => 'completado',
            'inicio'             => $inicio,
            'fin'                => $fin,
            'duracion_segundos'  => $fin->diffInSeconds($inicio),
            'items_procesados'   => $this->faker->numberBetween(1, 100),
            'items_resultado'    => $this->faker->numberBetween(0, 20),
            'errores'            => 0,
            'mensaje_error'      => null,
        ];
    }

    /**
     * Log de script "scraper".
     */
    public function scraper(): static
    {
        return $this->state(['script' => 'scraper']);
    }

    /**
     * Log de script "pep_monitor".
     */
    public function pepMonitor(): static
    {
        return $this->state(['script' => 'pep_monitor']);
    }

    /**
     * Log reciente (X minutos atrás).
     */
    public function reciente(int $minutosAtras = 30): static
    {
        $inicio = now()->subMinutes($minutosAtras);
        $fin = (clone $inicio)->addSeconds(120);

        return $this->state([
            'inicio'            => $inicio,
            'fin'               => $fin,
            'duracion_segundos' => 120,
            'estado'            => 'completado',
        ]);
    }

    /**
     * Log antiguo (X horas atrás).
     */
    public function haceHoras(int $horas): static
    {
        $inicio = now()->subHours($horas);
        $fin = (clone $inicio)->addSeconds(120);

        return $this->state([
            'inicio'            => $inicio,
            'fin'               => $fin,
            'duracion_segundos' => 120,
            'estado'            => 'completado',
        ]);
    }

    /**
     * Log con estado de error.
     */
    public function conError(string $mensaje = 'Error desconocido'): static
    {
        return $this->state([
            'estado'        => 'error',
            'errores'       => 1,
            'mensaje_error' => $mensaje,
        ]);
    }
}
