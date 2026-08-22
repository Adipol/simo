<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SiteValidationStatus;
use App\Models\SitioWeb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SitioWeb>
 */
class SitioWebFactory extends Factory
{
    protected $model = SitioWeb::class;

    public function definition(): array
    {
        return [
            'url' => $this->faker->unique()->url(),
            'nombre' => $this->faker->company(),
            'pais' => 'BO',
            'activo' => true,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Valid,
            'validated_at' => now(),
        ];
    }
}
