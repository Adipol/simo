<?php
declare(strict_types=1);
namespace Tests\Unit\Gemini;

use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\PepCatalogService;
use PHPUnit\Framework\TestCase;

class PromptReglasTest extends TestCase
{
    private GeminiPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new GeminiPromptBuilder;
    }

    public function test_prompt_contiene_reglas_de_clasificacion(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'PEP-designacion');
        $this->assertStringContainsString('REGLAS DE CLASIFICACIÓN', $prompt);
        $this->assertStringContainsString('EXPLÍCITAMENTE', $prompt);
        $this->assertStringContainsString('SUJETO ACTIVO', $prompt);
    }

    public function test_dynamic_prompt_contiene_reglas(): void
    {
        $cargos = collect([(object) ['nombre' => 'Diputado', 'entidad_tipo' => 'todas']]);
        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn($cargos);
        $catalog->method('getEntidades')->willReturn(collect([]));
        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Test', 'Bolivia', 'OPI-crimen');
        $this->assertStringContainsString('REGLAS DE CLASIFICACIÓN', $prompt);
    }

    public function test_prompt_ejemplo_negativo_fiebre_amarilla(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'PEP-designacion');
        $this->assertStringContainsString('fiebre amarilla', $prompt);
    }

    public function test_prompt_ejemplo_negativo_protesta(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'OPI-crimen');
        $this->assertStringContainsString('protesta', $prompt);
    }

    public function test_prompt_ejemplo_negativo_hospital(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'PEP-designacion');
        $this->assertStringContainsString('hospital', $prompt);
    }

    public function test_prompt_tiene_minimo_cuatro_neg(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'PEP-designacion');
        $this->assertGreaterThanOrEqual(4, substr_count($prompt, '[NEG]'));
    }

    public function test_contexto_categoria_crimen(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'OPI-crimen');
        $this->assertStringContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
        $this->assertStringContainsString('actor institucional', $prompt);
    }

    public function test_contexto_categoria_designacion(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'PEP-designacion');
        $this->assertStringContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
    }

    public function test_contexto_categoria_renuncia(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'PEP-renuncia');
        $this->assertStringContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
    }

    public function test_sin_contexto_para_categoria_desconocida(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Bolivia', 'otra');
        $this->assertStringNotContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
    }
}
