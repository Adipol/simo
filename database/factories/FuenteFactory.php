<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fuente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fuente>
 */
class FuenteFactory extends Factory
{
    protected $model = Fuente::class;

    public function definition(): array
    {
        return [
            'url'       => $this->faker->unique()->url(),
            'nombre'    => $this->faker->company(),
            'pais'      => 'BO',
            'organismo' => $this->faker->company(),
            'nivel'     => 'nacional',
            'tipo'      => 'html',
            'activo'    => true,
        ];
    }
}
