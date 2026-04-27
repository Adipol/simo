<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResultadoScraping>
 */
class ResultadoScrapingFactory extends Factory
{
    protected $model = ResultadoScraping::class;

    public function definition(): array
    {
        return [
            'url' => $this->faker->unique()->url(),
            'keyword' => $this->faker->words(3, true),
            'sitio_id' => SitioWeb::factory(),
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => $this->faker->numberBetween(10, 100),
            'found_in_title' => false,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre' => $this->faker->name(),
            'gemini_cargo' => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo' => 'Es un PEP identificado.',
        ];
    }

    public function sinAnalizar(): static
    {
        return $this->state([
            'gemini_analyzed' => false,
            'gemini_is_pep' => null,
            'gemini_categoria' => null,
            'gemini_nombre' => null,
            'gemini_cargo' => null,
            'gemini_confianza' => null,
            'gemini_motivo' => null,
        ]);
    }

    public function withGeminiFailure(?string $motivo = null): static
    {
        return $this->state([
            'gemini_analyzed' => true,
            'gemini_is_pep' => false,
            'gemini_error_motivo' => $motivo,
            'gemini_categoria' => null,
            'gemini_nombre' => null,
            'gemini_cargo' => null,
            'gemini_confianza' => null,
            'gemini_motivo' => null,
        ]);
    }
}
