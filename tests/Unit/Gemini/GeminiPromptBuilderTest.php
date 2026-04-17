<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\PepCatalogService;
use PHPUnit\Framework\TestCase;

class GeminiPromptBuilderTest extends TestCase
{
    private GeminiPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new GeminiPromptBuilder;
    }

    // ============================================
    // filtroPEP tests
    // ============================================

    public function test_filtro_pep_contains_required_json_fields(): void
    {
        $prompt = $this->builder->filtroPEP('El ministro Juan Pérez firmó el decreto', 'Argentina', 'PEP');

        $this->assertStringContainsString('personas', $prompt);
        $this->assertStringContainsString('nombre', $prompt);
        $this->assertStringContainsString('cargo', $prompt);
        $this->assertStringContainsString('categoria', $prompt);
        $this->assertStringContainsString('confianza', $prompt);
        $this->assertStringContainsString('motivo', $prompt);
        $this->assertStringContainsString('motivo_general', $prompt);
    }

    public function test_filtro_pep_contains_three_few_shot_examples(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP');

        // Should have PEP+, OPI+, NEG, and MULTI examples using personas array format
        $this->assertStringContainsString('[PEP+]', $prompt);
        $this->assertStringContainsString('[OPI+]', $prompt);
        $this->assertStringContainsString('[NEG]', $prompt);
        $this->assertStringContainsString('"personas"', $prompt);
        $this->assertStringContainsString('PEP', $prompt);
        $this->assertStringContainsString('OPI', $prompt);
    }

    public function test_filtro_pep_includes_country_and_category(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Paraguay', 'OPI');

        $this->assertStringContainsString('Paraguay', $prompt);
        $this->assertStringContainsString('OPI', $prompt);
    }

    public function test_filtro_pep_includes_input_text(): void
    {
        $text = 'El presidente designó a María García como nueva ministra';
        $prompt = $this->builder->filtroPEP($text, 'Argentina', 'PEP');

        $this->assertStringContainsString($text, $prompt);
    }

    public function test_filtro_pep_instructs_json_only_output(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Argentina', 'PEP');

        $this->assertStringContainsString('JSON', $prompt);
        $this->assertMatchesRegularExpression('/\{.*"personas".*\}/s', $prompt);
    }

    // ============================================
    // constructor injection tests (Task 6.1)
    // ============================================

    public function test_constructor_accepts_null_catalog_and_falls_back_to_generic(): void
    {
        $builder = new GeminiPromptBuilder(null);
        $prompt = $builder->filtroPEP('Test text', 'Chile', 'PEP');

        // Falls back to generic hardcoded definitions
        $this->assertStringContainsString('ministros', $prompt);
        $this->assertStringContainsString('is_pep', $prompt);
    }

    public function test_constructor_with_catalog_returning_positions_builds_dynamic_prompt(): void
    {
        $cargos = collect([
            (object) ['nombre' => 'Diputado', 'entidad_tipo' => 'todas'],
            (object) ['nombre' => 'Ministro', 'entidad_tipo' => 'publica'],
            (object) ['nombre' => 'Gerente', 'entidad_tipo' => 'ambas'],
        ]);
        $entidades = collect([
            (object) ['nombre' => 'YPFB'],
        ]);

        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn($cargos);
        $catalog->method('getEntidades')->willReturn($entidades);

        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Test text', 'Bolivia', 'PEP');

        $this->assertStringContainsString('SIEMPRE_PEP', $prompt);
        $this->assertStringContainsString('PEP_EN_ENTIDAD_PUBLICA', $prompt);
        $this->assertStringContainsString('PUEDE_SER_PEP', $prompt);
    }

    // ============================================
    // 3-section dynamic prompt structure (Task 6.3)
    // ============================================

    public function test_dynamic_prompt_siempre_pep_contains_todas_positions(): void
    {
        $cargos = collect([
            (object) ['nombre' => 'Diputado', 'entidad_tipo' => 'todas'],
            (object) ['nombre' => 'Senador', 'entidad_tipo' => 'todas'],
            (object) ['nombre' => 'Ministro', 'entidad_tipo' => 'publica'],
        ]);
        $entidades = collect([]);

        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn($cargos);
        $catalog->method('getEntidades')->willReturn($entidades);

        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Text', 'Bolivia', 'PEP');

        $this->assertStringContainsString('Diputado', $prompt);
        $this->assertStringContainsString('Senador', $prompt);
    }

    public function test_dynamic_prompt_pep_en_entidad_publica_contains_publica_positions(): void
    {
        $cargos = collect([
            (object) ['nombre' => 'Ministro', 'entidad_tipo' => 'publica'],
            (object) ['nombre' => 'Rector', 'entidad_tipo' => 'publica'],
        ]);
        $entidades = collect([]);

        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn($cargos);
        $catalog->method('getEntidades')->willReturn($entidades);

        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Text', 'Bolivia', 'PEP');

        $this->assertStringContainsString('Ministro', $prompt);
        $this->assertStringContainsString('Rector', $prompt);
    }

    public function test_dynamic_prompt_puede_ser_pep_contains_ambas_positions_and_entities(): void
    {
        $cargos = collect([
            (object) ['nombre' => 'Gerente', 'entidad_tipo' => 'ambas'],
            (object) ['nombre' => 'Director', 'entidad_tipo' => 'ambas'],
        ]);
        $entidades = collect([
            (object) ['nombre' => 'YPFB'],
            (object) ['nombre' => 'ENDE'],
        ]);

        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn($cargos);
        $catalog->method('getEntidades')->willReturn($entidades);

        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Text', 'Bolivia', 'PEP');

        $this->assertStringContainsString('Gerente', $prompt);
        $this->assertStringContainsString('Director', $prompt);
        $this->assertStringContainsString('YPFB', $prompt);
        $this->assertStringContainsString('ENDE', $prompt);
    }

    // ============================================
    // fallback behavior (Task 6.5)
    // ============================================

    public function test_catalog_with_empty_positions_falls_back_to_generic_prompt(): void
    {
        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn(collect([]));
        $catalog->method('getEntidades')->willReturn(collect([]));

        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Text', 'Chile', 'PEP');

        // Falls back to generic with hardcoded definitions
        $this->assertStringContainsString('ministros', $prompt);
        $this->assertStringNotContainsString('SIEMPRE_PEP', $prompt);
    }

    // ============================================
    // entidad_tipo field in JSON spec (Task 6.4 / 6.6)
    // ============================================

    public function test_dynamic_prompt_json_spec_includes_entidad_tipo_field(): void
    {
        $cargos = collect([
            (object) ['nombre' => 'Diputado', 'entidad_tipo' => 'todas'],
        ]);

        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn($cargos);
        $catalog->method('getEntidades')->willReturn(collect([]));

        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Text', 'Bolivia', 'PEP');

        $this->assertStringContainsString('entidad_tipo', $prompt);
    }

    public function test_generic_prompt_json_spec_includes_entidad_tipo_field(): void
    {
        $prompt = $this->builder->filtroPEP('Test', 'Argentina', 'PEP');

        $this->assertStringContainsString('entidad_tipo', $prompt);
    }

    // ============================================
    // Reglas de clasificación (mejorar-filtro-gemini)
    // ============================================

    public function test_prompt_contains_reglas_de_clasificacion_section(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP-designacion');

        $this->assertStringContainsString('REGLAS DE CLASIFICACIÓN', $prompt);
        $this->assertStringContainsString('EXPLÍCITAMENTE', $prompt);
        $this->assertStringContainsString('PRINCIPALMENTE', $prompt);
        $this->assertStringContainsString('SUJETO ACTIVO', $prompt);
    }

    public function test_dynamic_prompt_contains_reglas_de_clasificacion_section(): void
    {
        $cargos = collect([
            (object) ['nombre' => 'Diputado', 'entidad_tipo' => 'todas'],
        ]);

        $catalog = $this->createMock(PepCatalogService::class);
        $catalog->method('getCargos')->willReturn($cargos);
        $catalog->method('getEntidades')->willReturn(collect([]));

        $builder = new GeminiPromptBuilder($catalog);
        $prompt = $builder->filtroPEP('Test text', 'Bolivia', 'OPI-crimen');

        $this->assertStringContainsString('REGLAS DE CLASIFICACIÓN', $prompt);
    }

    // ============================================
    // Ejemplos negativos (mejorar-filtro-gemini)
    // ============================================

    public function test_prompt_contains_negative_examples_fiebre_amarilla(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP-designacion');

        $this->assertStringContainsString('fiebre amarilla', $prompt);
    }

    public function test_prompt_contains_negative_examples_protesta_social(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'OPI-crimen');

        $this->assertStringContainsString('protesta', $prompt);
    }

    public function test_prompt_contains_negative_example_directora_hospital(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP-designacion');

        $this->assertStringContainsString('hospital', $prompt);
    }

    public function test_prompt_has_at_least_three_negative_examples(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP-designacion');

        // Count [NEG] markers — should be at least 4 (1 original + 3 new)
        $this->assertGreaterThanOrEqual(4, substr_count($prompt, '[NEG]'));
    }

    // ============================================
    // Contexto de categoría (mejorar-filtro-gemini)
    // ============================================

    public function test_prompt_includes_crimen_category_context(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'OPI-crimen');

        $this->assertStringContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
        $this->assertStringContainsString('OPI-crimen', $prompt);
        $this->assertStringContainsString('actor institucional', $prompt);
    }

    public function test_prompt_includes_designacion_category_context(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP-designacion');

        $this->assertStringContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
        $this->assertStringContainsString('PEP-designacion', $prompt);
    }

    public function test_prompt_includes_renuncia_category_context(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP-renuncia');

        $this->assertStringContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
        $this->assertStringContainsString('PEP-renuncia', $prompt);
    }

    public function test_prompt_has_no_category_context_for_unknown_category(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'otra_cosa');

        $this->assertStringNotContainsString('CONTEXTO DE BÚSQUEDA', $prompt);
    }

    // ============================================
    // analisisCambio tests
    // ============================================

    public function test_analisis_cambio_contains_required_json_fields(): void
    {
        $prompt = $this->builder->analisisCambio(
            '+Juan Pérez\n-Carlos López',
            'Ministerio de Economía',
            'Ministerio'
        );

        $this->assertStringContainsString('persona_removida', $prompt);
        $this->assertStringContainsString('persona_nueva', $prompt);
        $this->assertStringContainsString('cargo', $prompt);
        $this->assertStringContainsString('es_mae', $prompt);
        $this->assertStringContainsString('riesgo', $prompt);
        $this->assertStringContainsString('analisis', $prompt);
    }

    public function test_analisis_cambio_contains_numbered_steps(): void
    {
        $prompt = $this->builder->analisisCambio('diff text', 'Fuente', 'Organismo');

        $this->assertMatchesRegularExpression('/\d+\./', $prompt); // At least one numbered step
        $this->assertStringContainsString('MAE', $prompt);
    }

    public function test_analisis_cambio_labels_before_and_after(): void
    {
        $prompt = $this->builder->analisisCambio('diff', 'Fuente', 'Organismo');

        $this->assertStringContainsString('ANTES:', $prompt);
        $this->assertStringContainsString('DESPUÉS:', $prompt);
    }

    public function test_analisis_cambio_includes_source_and_organism(): void
    {
        $prompt = $this->builder->analisisCambio('diff', 'eldeber.com.bo', 'Ministerio de Gobierno');

        $this->assertStringContainsString('eldeber.com.bo', $prompt);
        $this->assertStringContainsString('Ministerio de Gobierno', $prompt);
    }

    // ============================================
    // truncarDiff tests
    // ============================================

    public function test_truncar_diff_returns_unchanged_when_under_8000_chars(): void
    {
        $short = str_repeat('a', 5000);

        $result = $this->builder->truncarDiff($short);

        $this->assertSame($short, $result);
    }

    public function test_truncar_diff_concatenates_first_and_last_4000_when_8000_to_15000(): void
    {
        $text = str_repeat('a', 10000);

        $result = $this->builder->truncarDiff($text);

        $this->assertLessThanOrEqual(8500, strlen($result)); // 4000 + marker + 4000 + some buffer
        $this->assertStringContainsString('[... contenido truncado ...]', $result);
        $this->assertStringStartsWith('a', $result);
        $this->assertStringEndsWith('a', $result);
    }

    public function test_truncar_diff_extracts_change_lines_when_over_15000_chars(): void
    {
        // Create a large diff with +/- lines
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $lines[] = 'context line '.$i;
        }
        $lines[] = '+added line 1';
        $lines[] = '+added line 2';
        $lines[] = '-removed line 1';
        $lines[] = '-removed line 2';
        for ($i = 0; $i < 200; $i++) {
            $lines[] = 'more context '.$i;
        }

        $text = implode("\n", $lines);
        // Make it over 15000 chars
        while (strlen($text) < 16000) {
            $text .= "\nmore padding";
        }

        $result = $this->builder->truncarDiff($text);

        // Should contain the +/- lines
        $this->assertStringContainsString('+added line 1', $result);
        $this->assertStringContainsString('-removed line 1', $result);
        $this->assertLessThanOrEqual(13000, strlen($result));
    }

    public function test_truncar_diff_keeps_12000_char_limit(): void
    {
        // A very long diff with many +/- lines
        $lines = [];
        for ($i = 0; $i < 2000; $i++) {
            $lines[] = '+added line '.$i;
            $lines[] = '-removed line '.$i;
        }

        $text = implode("\n", $lines);
        $this->assertGreaterThan(15000, strlen($text));

        $result = $this->builder->truncarDiff($text);

        $this->assertLessThanOrEqual(13000, strlen($result)); // 12000 + some buffer for markers
    }
}
