<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Services\Gemini\GeminiPromptBuilder;
use PHPUnit\Framework\TestCase;

class GeminiPromptBuilderMultimodalTest extends TestCase
{
    private GeminiPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new GeminiPromptBuilder;
    }

    // ============================================
    // Task 3.1 / 3.2 — analisisCambioMultimodal body
    // ============================================

    public function test_analisis_cambio_multimodal_contains_multimodal_instruction(): void
    {
        $result = $this->builder->analisisCambioMultimodal(
            '+Juan Pérez\n-Carlos López',
            'Ministerio de Economía',
            'Bolivia',
            1,
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('imagen', $result);
        $this->assertStringContainsString('AMBOS', $result);
    }

    public function test_analisis_cambio_multimodal_mentions_image_count(): void
    {
        $result = $this->builder->analisisCambioMultimodal(
            'diff texto',
            'Fuente',
            'Organismo',
            3,
        );

        // Must mention number of images
        $this->assertStringContainsString('3', $result);
    }

    public function test_analisis_cambio_multimodal_contains_required_json_fields(): void
    {
        $result = $this->builder->analisisCambioMultimodal(
            '+Ana García\n-Pedro Álvarez',
            'Ministerio',
            'Organismo',
            2,
        );

        $this->assertStringContainsString('persona_removida', $result);
        $this->assertStringContainsString('persona_nueva', $result);
        $this->assertStringContainsString('cargo', $result);
        $this->assertStringContainsString('es_mae', $result);
        $this->assertStringContainsString('riesgo', $result);
        $this->assertStringContainsString('analisis', $result);
    }

    public function test_analisis_cambio_multimodal_includes_fuente_and_organismo(): void
    {
        $result = $this->builder->analisisCambioMultimodal(
            'diff',
            'ministerio.gob.bo',
            'Ministerio de Gobierno',
            1,
        );

        $this->assertStringContainsString('ministerio.gob.bo', $result);
        $this->assertStringContainsString('Ministerio de Gobierno', $result);
    }

    public function test_analisis_cambio_multimodal_includes_diff_text(): void
    {
        $diff = '+Luis Flores Director de Operaciones';
        $result = $this->builder->analisisCambioMultimodal(
            $diff,
            'Fuente',
            'Organismo',
            1,
        );

        $this->assertStringContainsString($diff, $result);
    }

    // ============================================
    // Task 3.3 / 3.4 — truncarDiff reuse
    // ============================================

    public function test_analisis_cambio_multimodal_truncates_long_diff(): void
    {
        // Create a diff between 8000-15000 chars to trigger Case 2 truncation (first+last 4000 with marker)
        $longDiff = str_repeat('a', 10000);

        $result = $this->builder->analisisCambioMultimodal(
            $longDiff,
            'Fuente',
            'Organismo',
            1,
        );

        // The long diff must have been truncated — the full 10000-char string won't appear intact
        $this->assertStringNotContainsString($longDiff, $result);
        // Result should contain truncation marker from truncarDiff Case 2
        $this->assertStringContainsString('[... contenido truncado ...]', $result);
    }

    public function test_analisis_cambio_multimodal_does_not_truncate_short_diff(): void
    {
        $shortDiff = '+Juan Pérez\n-Carlos López';

        $result = $this->builder->analisisCambioMultimodal(
            $shortDiff,
            'Fuente',
            'Organismo',
            1,
        );

        $this->assertStringContainsString($shortDiff, $result);
        $this->assertStringNotContainsString('[... contenido truncado ...]', $result);
    }
}
