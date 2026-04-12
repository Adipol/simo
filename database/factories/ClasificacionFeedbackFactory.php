<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategoriaCorreccion;
use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClasificacionFeedback>
 */
class ClasificacionFeedbackFactory extends Factory
{
    protected $model = ClasificacionFeedback::class;

    public function definition(): array
    {
        return [
            'resultado_scraping_id' => ResultadoScraping::factory(),
            'usuario_id' => User::factory(),
            'tipo' => $this->faker->randomElement(TipoFeedback::cases()),
            'clasificacion_snapshot' => [
                'is_pep' => true,
                'categoria' => 'PEP',
                'confianza' => 85,
                'nombre' => 'Nombre Test',
                'cargo' => 'Cargo Test',
            ],
            'corregido_is_pep' => null,
            'corregido_categoria' => null,
            'corregido_nombre' => null,
            'corregido_cargo' => null,
            'motivo' => null,
        ];
    }

    public function correcto(): static
    {
        return $this->state(['tipo' => TipoFeedback::Correcto]);
    }

    public function incorrecto(): static
    {
        return $this->state([
            'tipo' => TipoFeedback::Incorrecto,
            'corregido_categoria' => $this->faker->randomElement(CategoriaCorreccion::cases()),
            'motivo' => $this->faker->sentence(15),
        ]);
    }
}
