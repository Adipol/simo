<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cambio>
 */
class CambioFactory extends Factory
{
    protected $model = Cambio::class;

    public function definition(): array
    {
        return [
            'fuente_id'       => FuenteFactory::new(),
            'fecha'           => now(),
            'hash_anterior'   => $this->faker->sha256(),
            'hash_nuevo'      => $this->faker->sha256(),
            'lineas_quitadas' => $this->faker->numberBetween(0, 5),
            'lineas_nuevas'   => $this->faker->numberBetween(0, 5),
            'diff_texto'      => null,
            'posibles_peps'   => null,
            'revisado'        => false,
            'gemini_analyzed' => false,
            'gemini_analisis_json' => null,
            'imagenes_cambio_json' => null,
        ];
    }

    /**
     * Cambio con fecha antigua (más de N días).
     */
    public function viejo(int $dias = 100): static
    {
        return $this->state(['fecha' => now()->subDays($dias)]);
    }
}
