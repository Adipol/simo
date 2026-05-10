<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Models\Cambio;
use App\Models\ResultadoScraping;
use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardSummaryService;
use App\Services\Dashboard\DTOs\TriageStripDTO;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR1.4 — T56: Sparkline 7-element enforcement.
 *
 * DashboardSummaryService MUST always return sparkline arrays with
 * exactly 7 elements, regardless of how much data exists.
 *
 * Tests:
 *  - 0 days of data → 7 zeros
 *  - 1 day of data (today only) → 7 elements (6 zeros + today's count)
 *  - 14 days of data → 7 elements (only last 7 days kept)
 *  - mixed sparse data → always 7 elements
 */
class SparklineEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): DashboardSummaryService
    {
        return app(DashboardSummaryService::class);
    }

    private function getTriageStrip(): TriageStripDTO
    {
        // Bust cache to force fresh computation
        app(DashboardCacheManager::class)->forget('dashboard:summary');
        $service = $this->makeService();

        return $service->getSnapshot()->triage;
    }

    // ─── T56: Zero data ───────────────────────────────────────────────────────

    /**
     * With 0 data points, all 4 sparklines must have exactly 7 elements (all zeros).
     */
    public function test_sparklines_have_7_elements_with_no_data(): void
    {
        $triage = $this->getTriageStrip();

        $this->assertCount(7, $triage->sparkline_alto, 'sparkline_alto must have 7 elements with no data');
        $this->assertCount(7, $triage->sparkline_medio, 'sparkline_medio must have 7 elements with no data');
        $this->assertCount(7, $triage->sparkline_bajo, 'sparkline_bajo must have 7 elements with no data');
        $this->assertCount(7, $triage->sparkline_sin_leer, 'sparkline_sin_leer must have 7 elements with no data');
    }

    /**
     * Triangulation: with no data, all sparkline values are 0.
     */
    public function test_sparklines_are_all_zeros_with_no_data(): void
    {
        $triage = $this->getTriageStrip();

        $this->assertSame(array_fill(0, 7, 0), $triage->sparkline_alto);
        $this->assertSame(array_fill(0, 7, 0), $triage->sparkline_medio);
        $this->assertSame(array_fill(0, 7, 0), $triage->sparkline_bajo);
        $this->assertSame(array_fill(0, 7, 0), $triage->sparkline_sin_leer);
    }

    // ─── T56: Single day of data ──────────────────────────────────────────────

    /**
     * With 1 cambio from today, alto sparkline has 7 elements:
     * [0, 0, 0, 0, 0, 0, 1] (last element is today's count).
     */
    public function test_sparklines_have_7_elements_with_1_day_of_data(): void
    {
        // Create 1 alto-risk cambio today
        Cambio::factory()->create([
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'alto'],
            'fecha'                => now(),
        ]);

        $triage = $this->getTriageStrip();

        // All sparklines must still have exactly 7 elements
        $this->assertCount(7, $triage->sparkline_alto);
        $this->assertCount(7, $triage->sparkline_medio);
        $this->assertCount(7, $triage->sparkline_bajo);
        $this->assertCount(7, $triage->sparkline_sin_leer);

        // The alto sparkline must reflect the data (last element = today's count)
        $altoSparkline = $triage->sparkline_alto;
        $todayCount = end($altoSparkline);
        $this->assertGreaterThanOrEqual(1, $todayCount, 'Last sparkline element should reflect today data');
    }

    // ─── T56: 14 days of data (excess pruned to 7) ───────────────────────────

    /**
     * With 14 days of data, only the last 7 days are returned.
     * Sparkline always has exactly 7 elements.
     */
    public function test_sparklines_have_7_elements_with_14_days_of_data(): void
    {
        // Create 2 alto-risk cambios per day for 14 days
        for ($i = 13; $i >= 0; $i--) {
            Cambio::factory()
                ->count(2)
                ->create([
                    'revisado'             => false,
                    'gemini_analyzed'      => true,
                    'gemini_analisis_json' => ['riesgo' => 'alto'],
                    'fecha'                => now()->subDays($i),
                ]);
        }

        $triage = $this->getTriageStrip();

        // Must always be exactly 7 elements
        $this->assertCount(7, $triage->sparkline_alto, '14 days of data must still produce 7-element sparkline');
        $this->assertCount(7, $triage->sparkline_medio);
        $this->assertCount(7, $triage->sparkline_bajo);
        $this->assertCount(7, $triage->sparkline_sin_leer);
    }

    /**
     * Triangulation: with 14 days of alto data, the 7-element sparkline
     * contains non-zero values for recent days (not all zeros).
     */
    public function test_sparkline_values_are_nonzero_when_data_exists(): void
    {
        // Create cambios for last 7 days
        for ($i = 6; $i >= 0; $i--) {
            Cambio::factory()->create([
                'revisado'             => false,
                'gemini_analyzed'      => true,
                'gemini_analisis_json' => ['riesgo' => 'alto'],
                'fecha'                => now()->subDays($i),
            ]);
        }

        $triage = $this->getTriageStrip();

        // alto sparkline should have all non-zero values (1 per day)
        $alto = $triage->sparkline_alto;
        $this->assertCount(7, $alto);
        $this->assertGreaterThan(0, array_sum($alto), 'Sparkline must have non-zero sum when data exists');
    }

    // ─── T56: sin_leer sparkline ──────────────────────────────────────────────

    /**
     * sin_leer sparkline from ResultadoScraping also has exactly 7 elements.
     */
    public function test_sin_leer_sparkline_has_7_elements_with_data(): void
    {
        // Create 3 unread resultados today
        ResultadoScraping::factory()
            ->count(3)
            ->create([
                'leido'            => false,
                'fecha_encontrado' => now(),
            ]);

        $triage = $this->getTriageStrip();

        $this->assertCount(7, $triage->sparkline_sin_leer);

        // Last element should be 3 (today's count)
        $sinLeer = $triage->sparkline_sin_leer;
        $this->assertEquals(3, end($sinLeer), 'Last element should be today\'s count');
    }
}
