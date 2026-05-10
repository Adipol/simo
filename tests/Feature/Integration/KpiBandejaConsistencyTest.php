<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Livewire\Pep\Cambios as CambiosComponent;
use App\Livewire\Scraper\Resultados as ResultadosComponent;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\ResultadoScraping;
use App\Models\User;
use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardSummaryService;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Integration tests: KPI count must equal bandeja count when user clicks through.
 *
 * Prevents the recurring class of bug where the KPI shows N but the destination
 * page shows a different number. Three real bugs motivated this suite:
 *  - KPI "37 sin revisar" but bandeja showed 2 — fixed in PR #5 (scope conPersona)
 *  - KPI "Riesgo medio: 0" but bandeja showed 3 — fixed in PR #11 (filtroRevisado=0)
 *  - KPI "Sin leer: 4" but page showed 1 — fixed in PR #11 (filtroLeido=no truthy bug)
 *
 * Reference: openspec/archive/2026-05-10-redesign-dashboard/
 */
class KpiBandejaConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Fuente $fuente;

    private DashboardSummaryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesPermisosSeeder::class);

        $this->admin = User::factory()->create(['activo' => true]);
        $this->admin->assignRole('admin');

        $this->fuente = Fuente::factory()->create();

        Cache::flush();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);
        config(['dashboard.summary_cache_ttl' => 60]);
        config(['dashboard.hero_formula' => [
            'riesgo_alto_weight' => 3,
            'es_mae_weight'      => 2,
            'aging_divisor'      => 3,
        ]]);

        $this->service = new DashboardSummaryService(new DashboardCacheManager());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a Cambio with full control over risk level, revisado, and persona.
     */
    private function makeCambioWithRiesgo(
        string $riesgo,
        bool $revisado,
        ?string $personaNueva = 'Test Person',
    ): Cambio {
        return Cambio::factory()->create([
            'fuente_id'            => $this->fuente->id,
            'revisado'             => $revisado,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => array_filter([
                'riesgo'       => $riesgo,
                'persona_nueva' => $personaNueva,
            ], fn ($v) => $v !== null),
        ]);
    }

    // =========================================================================
    // Test 1: Triage strip "Alto riesgo" KPI matches bandeja
    // URL: /pep/cambios?filtroRiesgo=alto&filtroRevisado=0
    // =========================================================================

    public function test_alto_riesgo_kpi_matches_bandeja_count(): void
    {
        // 3 should count: alto + pendiente + persona detectada
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('alto', false);

        // 2 should NOT count: alto pero revisado
        $this->makeCambioWithRiesgo('alto', true);
        $this->makeCambioWithRiesgo('alto', true);

        // 1 should NOT count: alto + pendiente pero sin persona
        $this->makeCambioWithRiesgo('alto', false, null);

        Cache::flush();

        $kpiCount = $this->service->getSnapshot()->triage->pendientes_alto;

        $bandejaCount = Livewire::actingAs($this->admin)
            ->withQueryParams([
                'filtroRiesgo'    => 'alto',
                'filtroRevisado'  => '0',
                // filtroConPersona='si' is the default — no need to pass
            ])
            ->test(CambiosComponent::class)
            ->viewData('cambios')
            ->total();

        $this->assertSame(3, $kpiCount, 'KPI count mismatch from expected fixtures');
        $this->assertSame($kpiCount, $bandejaCount,
            'KPI Alto Riesgo no coincide con bandeja destino — recurring class of bug detected');
    }

    // =========================================================================
    // Test 2: Triage strip "Riesgo medio" KPI matches bandeja
    // URL: /pep/cambios?filtroRiesgo=medio&filtroRevisado=0
    // =========================================================================

    public function test_medio_riesgo_kpi_matches_bandeja_count(): void
    {
        // 4 should count: medio + pendiente + persona
        $this->makeCambioWithRiesgo('medio', false);
        $this->makeCambioWithRiesgo('medio', false);
        $this->makeCambioWithRiesgo('medio', false);
        $this->makeCambioWithRiesgo('medio', false);

        // 1 should NOT count: medio pero revisado
        $this->makeCambioWithRiesgo('medio', true);

        // 2 should NOT count: diferente riesgo
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('bajo', false);

        // 1 should NOT count: medio + pendiente pero sin persona
        $this->makeCambioWithRiesgo('medio', false, null);

        Cache::flush();

        $kpiCount = $this->service->getSnapshot()->triage->pendientes_medio;

        $bandejaCount = Livewire::actingAs($this->admin)
            ->withQueryParams([
                'filtroRiesgo'   => 'medio',
                'filtroRevisado' => '0',
            ])
            ->test(CambiosComponent::class)
            ->viewData('cambios')
            ->total();

        $this->assertSame(4, $kpiCount, 'KPI medio count mismatch from expected fixtures');
        $this->assertSame($kpiCount, $bandejaCount,
            'KPI Medio Riesgo no coincide con bandeja destino — recurring class of bug detected');
    }

    // =========================================================================
    // Test 3: Triage strip "Bajo riesgo" KPI matches bandeja
    // URL: /pep/cambios?filtroRiesgo=bajo&filtroRevisado=0
    // =========================================================================

    public function test_bajo_riesgo_kpi_matches_bandeja_count(): void
    {
        // 2 should count: bajo + pendiente + persona
        $this->makeCambioWithRiesgo('bajo', false);
        $this->makeCambioWithRiesgo('bajo', false);

        // 1 should NOT count: bajo pero revisado
        $this->makeCambioWithRiesgo('bajo', true);

        // 1 should NOT count: bajo + pendiente pero sin persona
        $this->makeCambioWithRiesgo('bajo', false, null);

        // These cambios must NOT bleed into bajo count
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('medio', false);

        Cache::flush();

        $kpiCount = $this->service->getSnapshot()->triage->pendientes_bajo;

        $bandejaCount = Livewire::actingAs($this->admin)
            ->withQueryParams([
                'filtroRiesgo'   => 'bajo',
                'filtroRevisado' => '0',
            ])
            ->test(CambiosComponent::class)
            ->viewData('cambios')
            ->total();

        $this->assertSame(2, $kpiCount, 'KPI bajo count mismatch from expected fixtures');
        $this->assertSame($kpiCount, $bandejaCount,
            'KPI Bajo Riesgo no coincide con bandeja destino — recurring class of bug detected');
    }

    // =========================================================================
    // Test 4: Triage strip "Sin leer" KPI matches /scraper/resultados?filtroLeido=0
    //
    // The KPI counts: leido=false, descartado=false, noArchivado().
    // The bandeja default (filtroGemini='') shows: gemini_analyzed=true,
    // secundario_de IS NULL, descartado=false, noArchivado().
    // With filtroLeido='0': also filters leido=false.
    //
    // Fixtures use gemini_analyzed=true so both sides agree on scope.
    // =========================================================================

    public function test_sin_leer_kpi_matches_bandeja_count(): void
    {
        // 3 should count: leido=false, descartado=false, no archivado, analyzed=true
        ResultadoScraping::factory()->count(3)->create([
            'leido'           => false,
            'descartado'      => false,
            'archivado_at'    => null,
            'gemini_analyzed' => true,
            'secundario_de'   => null,
        ]);

        // 2 should NOT count: leido=false pero descartado=true
        // KPI excludes (descartado filter), bandeja default excludes (filtroDescartado='0')
        ResultadoScraping::factory()->count(2)->create([
            'leido'           => false,
            'descartado'      => true,
            'archivado_at'    => null,
            'gemini_analyzed' => true,
            'secundario_de'   => null,
        ]);

        // 1 should NOT count: leido=false pero archivado
        // KPI excludes (noArchivado), bandeja default excludes (filtroArchivado='0')
        ResultadoScraping::factory()->create([
            'leido'           => false,
            'descartado'      => false,
            'archivado_at'    => now()->subHour(),
            'gemini_analyzed' => true,
            'secundario_de'   => null,
        ]);

        // 2 should NOT count: ya leidos
        ResultadoScraping::factory()->count(2)->create([
            'leido'           => true,
            'descartado'      => false,
            'archivado_at'    => null,
            'gemini_analyzed' => true,
            'secundario_de'   => null,
        ]);

        Cache::flush();

        $kpiCount = $this->service->getSnapshot()->triage->sin_leer;

        // The dashboard "Sin leer" card links to /scraper/resultados?filtroLeido=0
        // filtroLeido='0' → (bool)'0' === false → WHERE leido = false  (fixed bug: 'no' was truthy)
        // filtroDescartado='0' is the default → excludes descartados
        // filtroArchivado='0' is the default → excludes archivados
        // filtroGemini='' is the default → only gemini_analyzed=true, secundario_de IS NULL
        $bandejaCount = Livewire::actingAs($this->admin)
            ->withQueryParams([
                'filtroLeido' => '0',
                // Other params at component defaults: filtroDescartado='0', filtroArchivado='0', filtroGemini=''
            ])
            ->test(ResultadosComponent::class)
            ->viewData('resultados')
            ->total();

        $this->assertSame(3, $kpiCount, 'KPI sin_leer count mismatch from expected fixtures');
        $this->assertSame($kpiCount, $bandejaCount,
            'KPI Sin Leer no coincide con bandeja destino — este es el bug "filtroLeido=no era truthy"');
    }

    // =========================================================================
    // Test 5: Robustness check — DELIBERATE mismatch MUST FAIL
    //
    // This test verifies that our assertion infrastructure is working.
    // If a wrong URL (missing filtroRevisado=0) is used, the counts MUST differ.
    // Without this test we cannot know if tests 1-4 pass by correctness or coincidence.
    // =========================================================================

    public function test_assertion_catches_real_mismatches(): void
    {
        // 5 cambios that should count for BOTH KPI (alto+pendiente+persona) and bandeja
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('alto', false);
        $this->makeCambioWithRiesgo('alto', false);

        // 3 revisados: counted by bandeja WITHOUT filtroRevisado=0 but NOT by KPI
        $this->makeCambioWithRiesgo('alto', true);
        $this->makeCambioWithRiesgo('alto', true);
        $this->makeCambioWithRiesgo('alto', true);

        Cache::flush();

        $kpiCount = $this->service->getSnapshot()->triage->pendientes_alto; // = 5

        // Simulate the bug: forget filtroRevisado=0 → bandeja includes revisados too
        $bandejaWithoutFilter = Livewire::actingAs($this->admin)
            ->withQueryParams([
                'filtroRiesgo' => 'alto',
                // MISSING filtroRevisado=0 → shows pending + revisados = 8
            ])
            ->test(CambiosComponent::class)
            ->viewData('cambios')
            ->total();

        $this->assertSame(5, $kpiCount, 'Fixture setup: KPI must be 5');
        $this->assertSame(8, $bandejaWithoutFilter, 'Fixture setup: bandeja without filter must be 8');

        // The assertion MUST detect the mismatch
        $this->assertNotSame($kpiCount, $bandejaWithoutFilter,
            'If this assertion fails, the test infrastructure is broken — '.
            'KPI and bandeja-without-filter MUST differ when filter is missing');
    }
}
