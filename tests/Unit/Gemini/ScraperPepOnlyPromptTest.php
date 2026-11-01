<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\PepCatalogService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScraperPepOnlyPromptTest extends TestCase
{
    public static function promptBranches(): array
    {
        return ['generic' => [false], 'dynamic' => [true]];
    }

    #[DataProvider('promptBranches')]
    public function test_news_prompt_requests_only_pep_results(bool $dynamic): void
    {
        $prompt = $this->builder($dynamic)->filtroPEP('Texto de prueba', 'Bolivia', 'PEP-designacion');

        $this->assertStringNotContainsString('u OPI', $prompt);
        $this->assertStringNotContainsString('PEP/OPI', $prompt);
        $this->assertStringNotContainsString('- OPI:', $prompt);
        $this->assertStringNotContainsString('"categoria":"PEP"|"OPI"', $prompt);
        $this->assertStringContainsString('"categoria":"PEP"', $prompt);
        $this->assertStringContainsString('REGLAS DE CLASIFICACIÓN', $prompt);
        $this->assertStringContainsString('"evento":"designacion"|"renuncia"|"crimen"|null', $prompt);
    }

    #[DataProvider('promptBranches')]
    public function test_news_prompt_has_no_positive_opi_example(bool $dynamic): void
    {
        $prompt = $this->builder($dynamic)->filtroPEP('Texto de prueba', 'Bolivia', 'PEP-designacion');

        $this->assertStringNotContainsString('[OPI+]', $prompt);
        $this->assertStringNotContainsString('"categoria":"OPI"', $prompt);
        $this->assertStringContainsString('[PEP+]', $prompt);
        $this->assertStringContainsString('[NEG]', $prompt);
        $this->assertStringContainsString('[MULTI]', $prompt);
    }

    #[DataProvider('promptBranches')]
    public function test_legacy_crime_category_does_not_enable_non_pep_classification(bool $dynamic): void
    {
        $text = 'Una noticia OPI: el fiscal investiga a Rodrigo Vargas; designan al ministro Juan Pérez.';
        $prompt = $this->builder($dynamic)->filtroPEP($text, 'Bolivia', 'OPI-crimen');

        $this->assertStringContainsString("TEXTO:\n{$text}", $prompt);
        $this->assertStringContainsString('Categoría investigada: OPI-crimen', $prompt);
        $this->assertStringNotContainsString('actor institucional (funcionario, investigador, autoridad)', $prompt);
        $this->assertStringContainsString('solo clasificar como PEP', $prompt);
    }

    private function builder(bool $dynamic): GeminiPromptBuilder
    {
        if (! $dynamic) {
            return new GeminiPromptBuilder;
        }

        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->with('Bolivia')->willReturn(collect([
            (object) ['nombre' => 'Ministro', 'entidad_tipo' => 'publica'],
        ]));
        $catalog->method('getEntidades')->with('Bolivia')->willReturn(collect([]));

        return new GeminiPromptBuilder($catalog);
    }
}
