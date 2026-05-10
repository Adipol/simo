<?php

declare(strict_types=1);

namespace Tests\Feature\View\Dashboard;

use Tests\TestCase;

/**
 * T41 — RED tests for <x-simo-sparkline> SVG component.
 */
class SparklineTest extends TestCase
{
    // ─── 7-point data renders SVG with polyline ───────────────────────────────

    public function test_sparkline_renders_svg_element(): void
    {
        $html = view('components.simo-sparkline', [
            'data' => [1, 2, 3, 4, 5, 6, 7],
        ])->render();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('polyline', $html);
    }

    public function test_sparkline_renders_7_coordinate_pairs(): void
    {
        $html = view('components.simo-sparkline', [
            'data' => [1, 2, 3, 4, 5, 6, 7],
            'fill' => false, // skip polygon so first points= is the polyline
        ])->render();

        // Extract points attribute from polyline
        preg_match('/<polyline[^>]*points="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches, 'polyline must have points attribute');

        // Points are space-separated "x,y" pairs
        $pointPairs = preg_split('/\s+/', trim($matches[1]));
        $pointPairs = array_values(array_filter($pointPairs));
        $this->assertCount(7, $pointPairs, 'Sparkline must have exactly 7 coordinate pairs');
    }

    // ─── All-zero data renders flat line (no division by zero) ───────────────

    public function test_sparkline_all_zeros_renders_flat_line_without_error(): void
    {
        $html = view('components.simo-sparkline', [
            'data' => [0, 0, 0, 0, 0, 0, 0],
        ])->render();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('polyline', $html);
    }

    // ─── Single peak data renders without error ───────────────────────────────

    public function test_sparkline_single_peak_renders_without_error(): void
    {
        $html = view('components.simo-sparkline', [
            'data' => [0, 0, 0, 0, 0, 0, 10],
        ])->render();

        $this->assertStringContainsString('<svg', $html);
    }

    // ─── Fill prop is optional and defaults to true ───────────────────────────

    public function test_sparkline_with_fill_false_omits_polygon(): void
    {
        $htmlWithFill = view('components.simo-sparkline', [
            'data' => [1, 2, 3, 4, 5, 6, 7],
            'fill' => true,
        ])->render();

        $htmlWithoutFill = view('components.simo-sparkline', [
            'data' => [1, 2, 3, 4, 5, 6, 7],
            'fill' => false,
        ])->render();

        $this->assertStringContainsString('polygon', $htmlWithFill);
        $this->assertStringNotContainsString('polygon', $htmlWithoutFill);
    }

    // ─── Color prop changes stroke class ─────────────────────────────────────

    public function test_sparkline_emerald_color_uses_emerald_stroke(): void
    {
        $html = view('components.simo-sparkline', [
            'data' => [1, 2, 3, 4, 5, 6, 7],
            'color' => 'emerald',
        ])->render();

        $this->assertStringContainsString('emerald', $html);
    }
}
