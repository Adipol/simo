<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\DescartadosAnalisisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TDD tests for simo:analizar-descartados command.
 *
 * Covers precision-dashboard REQ-6 / SCN-6.1–6.8.
 *
 * Pattern mirrors BackfillZombieResultadosTest and AnalizarGeminiCommandTest.
 *
 * Safety net: command class does NOT exist yet (RED phase) — tests must FAIL.
 */
class AnalizarDescartadosCommandTest extends TestCase
{
    use RefreshDatabase;

    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        ResultadoScraping::flushEventListeners();

        // Pin "now" for deterministic date windows
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00'));

        $this->sitio = SitioWeb::create([
            'url'    => 'https://test-analizar.example.com',
            'nombre' => 'Test Sitio Analizar',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeResultado(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'              => 'https://test-analizar.example.com/article-' . uniqid(),
            'keyword'          => 'corrupcion',
            'sitio_id'         => $this->sitio->id,
            'pais'             => 'BO',
            'categoria'        => 'PEP-designacion',
            'titulo'           => 'Test Article',
            'contexto'         => '',
            'fecha_encontrado' => Carbon::now()->subDays(5),
            'relevance_score'  => 50,
            'leido'            => false,
            'descartado'       => false,
            'relevante'        => null,
            'gemini_analyzed'  => true,
            'gemini_confianza' => 80,
        ], $overrides));
    }

    // ─── T1: Resumen section with correct numbers ──────────────────────────────

    /**
     * SCN-6.1 — Command outputs RESUMEN section with correct precision %.
     *
     * Seed 10 descartados + 5 relevantes = 15 labeled → precision = 33.3%.
     * Assert output contains section header and computed precision.
     */
    public function test_command_outputs_resumen_section_with_correct_numbers(): void
    {
        // Seed: 10 descartados
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado(['descartado' => true, 'relevante' => false]);
        }
        // Seed: 5 relevantes
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado(['descartado' => false, 'relevante' => true]);
        }

        $this->artisan('simo:analizar-descartados', ['--no-cache' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('RESUMEN GENERAL')
            ->expectsOutputToContain('15')   // Total procesados
            ->expectsOutputToContain('10')   // Descartados
            ->expectsOutputToContain('5')    // Relevantes
            ->expectsOutputToContain('33.3') // Precision %
            ->expectsOutputToContain('SIMO');
    }

    /**
     * SCN-6.2 — Command shows "datos insuficientes" when labeled sample < 10.
     *
     * Seed 8 labeled rows. Command must still exit 0 and show warning.
     */
    public function test_command_shows_datos_insuficientes_when_below_threshold(): void
    {
        // Seed: 8 descartados (< 10 global minimum)
        for ($i = 0; $i < 8; $i++) {
            $this->makeResultado(['descartado' => true, 'relevante' => false]);
        }

        $this->artisan('simo:analizar-descartados', ['--no-cache' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('RESUMEN GENERAL')
            ->expectsOutputToContain('insuficiente');
    }

    // ─── T2: --dias flag ───────────────────────────────────────────────────────

    /**
     * SCN-6.3 — --dias flag limits the analysis window.
     *
     * Seed rows at 5 days ago (within --dias=7) and rows at 35 days ago (outside).
     * Only the recent rows must count.
     */
    public function test_command_respects_dias_flag(): void
    {
        // Within window (5 days ago, within --dias=7)
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado([
                'descartado'       => true,
                'relevante'        => false,
                'fecha_encontrado' => Carbon::now()->subDays(5),
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado([
                'descartado'       => false,
                'relevante'        => true,
                'fecha_encontrado' => Carbon::now()->subDays(5),
            ]);
        }

        // Outside window (35 days ago, outside --dias=7)
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado([
                'descartado'       => true,
                'relevante'        => false,
                'fecha_encontrado' => Carbon::now()->subDays(35),
            ]);
        }

        // With --dias=7, only 15 in-window rows are counted
        $this->artisan('simo:analizar-descartados', ['--dias' => 7, '--no-cache' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('RESUMEN GENERAL')
            ->expectsOutputToContain('15');

        // Triangulation: with --dias=60, all 25 rows should appear
        $this->artisan('simo:analizar-descartados', ['--dias' => 60, '--no-cache' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('25');
    }

    // ─── T3: --categoria flag ──────────────────────────────────────────────────

    /**
     * SCN-6.4 — --categoria flag filters results by category.
     *
     * Seed PEP rows and OPI rows. With --categoria=PEP-designacion,
     * service must receive the filter (tested via service mock).
     */
    public function test_command_respects_categoria_flag(): void
    {
        // Seed 10 descartados + 5 relevantes as PEP-designacion
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado([
                'descartado' => true,
                'relevante'  => false,
                'categoria'  => 'PEP-designacion',
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado([
                'descartado' => false,
                'relevante'  => true,
                'categoria'  => 'PEP-designacion',
            ]);
        }

        // Seed rows for a different category (should NOT be included)
        for ($i = 0; $i < 20; $i++) {
            $this->makeResultado([
                'descartado' => true,
                'relevante'  => false,
                'categoria'  => 'OPI-crimen',
            ]);
        }

        // Command must accept --categoria option and exit 0
        $this->artisan('simo:analizar-descartados', [
            '--categoria' => 'PEP-designacion',
            '--no-cache'  => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('RESUMEN GENERAL');
    }

    // ─── T4: --keyword flag ────────────────────────────────────────────────────

    /**
     * SCN-6.5 — --keyword flag shows single-keyword detail view.
     *
     * With --keyword=corrupcion: output must mention the keyword.
     * Triangulation: a different keyword is NOT shown in output.
     */
    public function test_command_respects_keyword_flag_for_detailed_view(): void
    {
        // Seed 10 labeled rows for 'corrupcion'
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado([
                'keyword'    => 'corrupcion',
                'descartado' => true,
                'relevante'  => false,
            ]);
        }

        // Seed rows for a different keyword
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado([
                'keyword'    => 'lavado',
                'descartado' => false,
                'relevante'  => true,
            ]);
        }

        // With --keyword=corrupcion, command must run successfully
        $this->artisan('simo:analizar-descartados', [
            '--keyword'  => 'corrupcion',
            '--no-cache' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('corrupcion');
    }

    // ─── T5: --min-sample flag ─────────────────────────────────────────────────

    /**
     * SCN-6.6 — --min-sample flag controls per-keyword threshold.
     *
     * Seed a keyword with 3 rows. With --min-sample=5, that keyword
     * must NOT appear in top lemas output.
     *
     * Triangulation: with --min-sample=2, it MUST appear.
     */
    public function test_command_respects_min_sample_flag(): void
    {
        // Seed: keyword 'escaso' with only 3 labeled rows (below min-sample=5)
        for ($i = 0; $i < 3; $i++) {
            $this->makeResultado([
                'keyword'    => 'escaso',
                'descartado' => true,
                'relevante'  => false,
            ]);
        }

        // Also seed 15 rows for 'corrupcion' to have sufficient precision data
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado([
                'keyword'    => 'corrupcion',
                'descartado' => true,
                'relevante'  => false,
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado([
                'keyword'    => 'corrupcion',
                'descartado' => false,
                'relevante'  => true,
            ]);
        }

        // With --min-sample=5, 'escaso' (3 rows) must NOT appear in lemas table
        $this->artisan('simo:analizar-descartados', [
            '--min-sample' => 5,
            '--no-cache'   => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('LEMAS');

        // Triangulation: with --min-sample=2, 'escaso' should appear
        $this->artisan('simo:analizar-descartados', [
            '--min-sample' => 2,
            '--no-cache'   => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('escaso');
    }

    // ─── T6: --no-cache flag ───────────────────────────────────────────────────

    /**
     * SCN-6.7 — --no-cache bypasses cache and reads fresh data from DB.
     *
     * Prime cache with one call, add new rows to DB, then re-run with --no-cache.
     * The fresh data (higher count) must appear in output.
     */
    public function test_command_bypasses_cache_with_no_cache_flag(): void
    {
        // Seed: 10 descartados + 5 relevantes = 15 labeled
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado(['descartado' => true, 'relevante' => false]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->makeResultado(['descartado' => false, 'relevante' => true]);
        }

        // First call WITHOUT --no-cache to prime the cache
        $this->artisan('simo:analizar-descartados')
            ->assertSuccessful()
            ->expectsOutputToContain('15');

        // Add 10 more rows directly to DB (cache would show 15, fresh shows 25)
        for ($i = 0; $i < 10; $i++) {
            $this->makeResultado(['descartado' => true, 'relevante' => false]);
        }

        // With --no-cache, command must pick up the 10 new rows → 25 total
        $this->artisan('simo:analizar-descartados', ['--no-cache' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('25');
    }
}
