<?php

declare(strict_types=1);

namespace Tests\Feature\View\Dashboard;

use App\Services\Dashboard\HeatmapPalette;
use Tests\TestCase;

/**
 * T42 — RED tests for <x-dashboard.latam-heatmap> SVG choropleth.
 */
class LatamHeatmapTest extends TestCase
{
    // ─── 10 ISO country codes present in SVG ─────────────────────────────────

    public function test_heatmap_renders_all_10_latam_iso_codes(): void
    {
        $counts = [];

        $html = view('components.dashboard.latam-heatmap', [
            'counts' => $counts,
        ])->render();

        foreach (HeatmapPalette::LATAM_ISO_CODES as $iso) {
            $this->assertStringContainsString($iso, $html, "ISO code {$iso} must appear in heatmap");
        }
    }

    // ─── Country with data gets dark fill ────────────────────────────────────

    public function test_heatmap_ar_with_count_12_gets_rose_fill(): void
    {
        $counts = ['AR' => 12, 'BR' => 2, 'CL' => 1];

        $html = view('components.dashboard.latam-heatmap', [
            'counts' => $counts,
        ])->render();

        // AR at max should get rose-500 fill (#f43f5e) — the darkest quintile bucket
        // HeatmapPalette::bucketColor(12, 12) = 100% → Q5 → bg-rose-500 → #f43f5e
        $this->assertStringContainsString('#f43f5e', $html);
    }

    // ─── Country with zero count gets grey fill ───────────────────────────────

    public function test_heatmap_co_with_zero_gets_grey_fill(): void
    {
        $counts = ['AR' => 5]; // CO not in counts → 0

        $html = view('components.dashboard.latam-heatmap', [
            'counts' => $counts,
        ])->render();

        // Grey fallback (#f3f4f6 = gray-100) must appear for zero countries
        $this->assertStringContainsString('#f3f4f6', $html);
    }

    // ─── All zeros shows grey overlay ────────────────────────────────────────

    public function test_heatmap_all_zero_shows_no_detections_message(): void
    {
        $html = view('components.dashboard.latam-heatmap', [
            'counts' => [],
        ])->render();

        $this->assertStringContainsString('detecciones', strtolower($html));
    }

    // ─── SVG title elements provide hover text ───────────────────────────────

    public function test_heatmap_svg_has_title_elements(): void
    {
        $counts = ['AR' => 5];

        $html = view('components.dashboard.latam-heatmap', [
            'counts' => $counts,
        ])->render();

        $this->assertStringContainsString('<title>', $html);
    }
}
