<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Services\Gemini\GeminiPromptBuilder;
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

        $this->assertStringContainsString('is_pep', $prompt);
        $this->assertStringContainsString('nombre', $prompt);
        $this->assertStringContainsString('cargo', $prompt);
        $this->assertStringContainsString('categoria', $prompt);
        $this->assertStringContainsString('confianza', $prompt);
        $this->assertStringContainsString('motivo', $prompt);
    }

    public function test_filtro_pep_contains_three_few_shot_examples(): void
    {
        $prompt = $this->builder->filtroPEP('Test text', 'Bolivia', 'PEP');

        // Count occurrences of example patterns
        // Should have PEP+, OPI+, and NEG examples
        $this->assertMatchesRegularExpression('/"is_pep":\s*true/', $prompt);
        $this->assertMatchesRegularExpression('/"is_pep":\s*false/', $prompt);
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
        $this->assertMatchesRegularExpression('/\{.*"is_pep".*\}/s', $prompt);
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
