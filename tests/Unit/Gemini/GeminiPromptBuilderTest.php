<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Models\ResultadoScraping;
use App\Services\Contracts\NegativeExamplesProvider;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\PepCatalogService;
use Illuminate\Support\Collection;
use Tests\TestCase;

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
        $this->assertStringContainsString('personas', $prompt);
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
        $this->assertStringContainsString('título formal', $prompt);
        $this->assertStringContainsString('descripción funcional', $prompt);
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

        $this->assertStringContainsString('DIFF:', $prompt);
    }

    public function test_analisis_cambio_includes_source_and_organism(): void
    {
        $prompt = $this->builder->analisisCambio('diff', 'eldeber.com.bo', 'Ministerio de Gobierno');

        $this->assertStringContainsString('eldeber.com.bo', $prompt);
        $this->assertStringContainsString('Ministerio de Gobierno', $prompt);
    }

    // ============================================
    // analisisCambio — false positive prevention (filtro-cambios-pep)
    // ============================================

    public function test_analisis_cambio_contains_paso_0_gate(): void
    {
        $prompt = $this->builder->analisisCambio(
            '- _Transparencia\n+ Transparencia',
            'Ministerio de Transparencia',
            'Ministerio'
        );

        $this->assertStringContainsString('PASO 0', $prompt);
    }

    public function test_analisis_cambio_contains_null_persona_rule(): void
    {
        $prompt = $this->builder->analisisCambio(
            '- _Transparencia\n+ Transparencia',
            'Ministerio',
            'Organismo'
        );

        $this->assertStringContainsString('DEBEN ser null', $prompt);
    }

    public function test_analisis_cambio_contains_neg_example_formatting_only(): void
    {
        $prompt = $this->builder->analisisCambio(
            '+ MINISTERIO DE SALUD Y DEPORTES',
            'Fuente',
            'Organismo'
        );

        $this->assertStringContainsString('_Transparencia', $prompt);
    }

    public function test_analisis_cambio_contains_neg_example_institutional_headline(): void
    {
        $prompt = $this->builder->analisisCambio(
            '+ MINISTERIO DE SALUD',
            'Fuente',
            'Organismo'
        );

        $this->assertStringContainsString('COORDINAN ACCIONES', $prompt);
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

    // ============================================
    // WU3 — Dynamic negative examples (REQ-5, REQ-7, REQ-9, REQ-10)
    // ============================================

    /**
     * REQ-5 / REQ-10: sin servicio inyectado → ejemplos hardcodeados.
     */
    public function test_construye_ejemplos_hardcodeados_cuando_servicio_es_null(): void
    {
        $builder = new GeminiPromptBuilder(null, null);
        $prompt = $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        $this->assertStringContainsString('fiebre amarilla', $prompt);
        $this->assertStringNotContainsString('[NEG-OP]', $prompt);
    }

    /**
     * REQ-7: flag deshabilitado → hardcodeados aunque el servicio esté inyectado.
     */
    public function test_flag_deshabilitado_usa_hardcodeados(): void
    {
        config(['services.gemini.negative_examples_enabled' => false]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->expects($this->never())->method('getNegativeExamples');

        $builder = new GeminiPromptBuilder(null, $service, 5);
        $prompt = $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        $this->assertStringContainsString('fiebre amarilla', $prompt);
        $this->assertStringNotContainsString('[NEG-OP]', $prompt);

        config(['services.gemini.negative_examples_enabled' => null]);
    }

    /**
     * REQ-5: servicio inyectado + flag habilitado + resultados → ejemplos dinámicos.
     */
    public function test_construye_ejemplos_dinamicos_cuando_flag_habilitado(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $descartado = new ResultadoScraping([
            'titulo'           => 'Renuncia del DT del club Bolívar',
            'gemini_motivo'    => 'Deportivo',
            'gemini_confianza' => 95,
        ]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->method('getNegativeExamples')->willReturn(collect([$descartado]));

        $builder = new GeminiPromptBuilder(null, $service, 5);
        $prompt = $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        $this->assertStringContainsString('[NEG-OP]', $prompt);
        $this->assertStringContainsString('Renuncia del DT del club Bolívar', $prompt);
        $this->assertStringNotContainsString('fiebre amarilla', $prompt);

        config(['services.gemini.negative_examples_enabled' => null]);
    }

    /**
     * REQ-5: servicio retorna colección vacía → fallback a hardcodeados.
     */
    public function test_servicio_retorna_vacio_usa_hardcodeados(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->method('getNegativeExamples')->willReturn(collect([]));

        $builder = new GeminiPromptBuilder(null, $service, 5);
        $prompt = $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        $this->assertStringContainsString('fiebre amarilla', $prompt);
        $this->assertStringNotContainsString('[NEG-OP]', $prompt);

        config(['services.gemini.negative_examples_enabled' => null]);
    }

    /**
     * REQ-9: formato exacto del ejemplo dinámico [NEG-OP].
     */
    public function test_formato_neg_op_es_correcto(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $descartado = new ResultadoScraping([
            'titulo'           => 'Renuncia del DT del club Bolívar',
            'gemini_motivo'    => 'Deportivo',
            'gemini_confianza' => 95,
        ]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->method('getNegativeExamples')->willReturn(collect([$descartado]));

        $builder = new GeminiPromptBuilder(null, $service, 5);
        $prompt = $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        $expected = '[NEG-OP] "Renuncia del DT del club Bolívar" → {"personas":[],"motivo_general":"Deportivo. Confianza original: 95."}';
        $this->assertStringContainsString($expected, $prompt);

        config(['services.gemini.negative_examples_enabled' => null]);
    }

    /**
     * REQ-5: respeta el límite de ejemplos negativos.
     */
    public function test_respeta_limite_de_ejemplos_negativos(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $descartados = collect([
            new ResultadoScraping(['titulo' => 'Artículo 1', 'gemini_motivo' => 'Motivo A', 'gemini_confianza' => 90]),
            new ResultadoScraping(['titulo' => 'Artículo 2', 'gemini_motivo' => 'Motivo B', 'gemini_confianza' => 85]),
            new ResultadoScraping(['titulo' => 'Artículo 3', 'gemini_motivo' => 'Motivo C', 'gemini_confianza' => 80]),
        ]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->expects($this->once())
            ->method('getNegativeExamples')
            ->with(3)
            ->willReturn($descartados);

        $builder = new GeminiPromptBuilder(null, $service, 3);
        $prompt = $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        $this->assertSame(3, substr_count($prompt, '[NEG-OP]'));

        config(['services.gemini.negative_examples_enabled' => null]);
    }

    /**
     * REQ-9: caracteres especiales en título se preservan sin corrupción.
     */
    public function test_caracteres_especiales_en_titulo_se_preservan(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $descartado = new ResultadoScraping([
            'titulo'           => 'Álvaro Pérez dimite',
            'gemini_motivo'    => 'Deportivo, sin cargo público',
            'gemini_confianza' => 88,
        ]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->method('getNegativeExamples')->willReturn(collect([$descartado]));

        $builder = new GeminiPromptBuilder(null, $service, 5);
        $prompt = $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        $this->assertStringContainsString('Álvaro Pérez dimite', $prompt);
        $this->assertStringContainsString('Deportivo, sin cargo público', $prompt);

        config(['services.gemini.negative_examples_enabled' => null]);
    }

    // ============================================
    // WU4 — Cache behavior + logging (REQ-6, REQ-8)
    // ============================================

    /**
     * REQ-6: la misma instancia reutiliza el cache — getNegativeExamples se llama UNA sola vez
     * aunque se invoque filtroPEP() dos veces.
     */
    public function test_cache_reusado_en_multiples_llamadas_misma_instancia(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $descartado = new ResultadoScraping([
            'titulo'           => 'Artículo deportivo',
            'gemini_motivo'    => 'Deportivo',
            'gemini_confianza' => 90,
        ]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->expects($this->once())
            ->method('getNegativeExamples')
            ->willReturn(collect([$descartado]));

        $builder = new GeminiPromptBuilder(null, $service, 5);
        $builder->filtroPEP('Texto uno', 'Bolivia', 'PEP');
        $builder->filtroPEP('Texto dos', 'Bolivia', 'PEP');

        // El mock lanzaría fallo si getNegativeExamples se llamara más de una vez
        config(['services.gemini.negative_examples_enabled' => null]);
    }

    /**
     * REQ-6: una nueva instancia consulta la DB de nuevo (cache es per-instance).
     */
    public function test_nueva_instancia_consulta_db_de_nuevo(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $descartado = new ResultadoScraping([
            'titulo'           => 'Artículo deportivo',
            'gemini_motivo'    => 'Deportivo',
            'gemini_confianza' => 90,
        ]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->expects($this->exactly(2))
            ->method('getNegativeExamples')
            ->willReturn(collect([$descartado]));

        $builder1 = new GeminiPromptBuilder(null, $service, 5);
        $builder1->filtroPEP('Texto uno', 'Bolivia', 'PEP');

        $builder2 = new GeminiPromptBuilder(null, $service, 5);
        $builder2->filtroPEP('Texto dos', 'Bolivia', 'PEP');

        // El mock verifica que se llamó exactamente 2 veces (una por instancia)
        config(['services.gemini.negative_examples_enabled' => null]);
    }

    /**
     * REQ-8: cuando se usan ejemplos dinámicos se registra el conteo en el log.
     */
    public function test_loguea_cuenta_cuando_inyecta_dinamicos(): void
    {
        config(['services.gemini.negative_examples_enabled' => true]);

        $descartados = collect([
            new ResultadoScraping(['titulo' => 'Art 1', 'gemini_motivo' => 'Motivo', 'gemini_confianza' => 90]),
            new ResultadoScraping(['titulo' => 'Art 2', 'gemini_motivo' => 'Motivo', 'gemini_confianza' => 85]),
        ]);

        $service = $this->createMock(NegativeExamplesProvider::class);
        $service->method('getNegativeExamples')->willReturn($descartados);

        \Illuminate\Support\Facades\Log::spy();

        $builder = new GeminiPromptBuilder(null, $service, 5);
        $builder->filtroPEP('Texto de prueba', 'Bolivia', 'PEP');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->once()
            ->with('gemini.negative_examples.injected', ['count' => 2]);

        config(['services.gemini.negative_examples_enabled' => null]);
    }
}
