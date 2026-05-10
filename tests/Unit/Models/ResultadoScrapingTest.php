<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultadoScrapingTest extends TestCase
{
    use RefreshDatabase;

    private function createResultado(array $overrides = []): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://test.com/article',
            'keyword' => 'test',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre' => 'Juan Perez',
            'gemini_cargo' => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo' => 'Es PEP',
        ], $overrides));
    }

}
