<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dashboard;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\ResultadoScraping;
use App\Services\Dashboard\DashboardCacheManager;
use App\Services\Dashboard\DashboardSummaryService;
use App\Services\Dashboard\DTOs\DashboardSummaryDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature tests for DashboardSummaryService.
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T18-T24 (Phase 3 — DashboardSummaryService)
 */
class DashboardSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardSummaryService $service;
    private DashboardCacheManager $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Disable external services so observers don't interfere
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        // Override TTLs so tests don't depend on real timing
        config(['dashboard.summary_cache_ttl' => 60]);
        config(['dashboard.backlog_aging_days' => 3]);
        config(['dashboard.discovery_min_confidence' => 0.8]);
        config(['dashboard.hero_formula' => [
            'riesgo_alto_weight' => 3,
            'es_mae_weight'      => 2,
            'aging_divisor'      => 3,
        ]]);

        $this->cache   = new DashboardCacheManager();
        $this->service = new DashboardSummaryService($this->cache);
    }

    // =========================================================================
    // T18 — getSnapshot() returns DashboardSummaryDTO
    // =========================================================================

    public function test_get_snapshot_returns_dashboard_summary_dto(): void
    {
        $snapshot = $this->service->getSnapshot();

        $this->assertInstanceOf(DashboardSummaryDTO::class, $snapshot);
    }

    // =========================================================================
    // T19 — Hero card is null when no pending cambios with persona
    // =========================================================================

    public function test_hero_is_null_when_no_pending_cambios(): void
    {
        $snapshot = $this->service->getSnapshot();

        $this->assertNull($snapshot->hero);
    }

    public function test_hero_is_null_when_cambios_are_all_revisado(): void
    {
        Cambio::factory()->create([
            'revisado'             => true,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Juan', 'riesgo' => 'alto', 'es_mae' => false],
        ]);

        $snapshot = $this->service->getSnapshot();

        $this->assertNull($snapshot->hero);
    }

    // =========================================================================
    // T20 — Hero card is populated from the highest-scoring unread cambio
    // =========================================================================

    public function test_hero_returns_highest_score_unread_cambio(): void
    {
        $fuente = Fuente::factory()->create(['nombre' => 'Gaceta Oficial']);

        // Low-score cambio: gemini_analyzed but no persona
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'bajo', 'es_mae' => false],
        ]);

        // High-score cambio: riesgo alto + es_mae = true + pending persona
        $hero = Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now()->subDays(6),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Eva Morales',
                'riesgo'        => 'alto',
                'es_mae'        => true,
            ],
        ]);

        $snapshot = $this->service->getSnapshot();

        $this->assertNotNull($snapshot->hero);
        $this->assertSame($hero->id, $snapshot->hero->id);
        $this->assertSame('Gaceta Oficial', $snapshot->hero->fuente_nombre);
        $this->assertSame('alto', $snapshot->hero->riesgo);
        $this->assertTrue($snapshot->hero->es_mae);
    }

    // =========================================================================
    // T21 — Hero respects conPersona semantics: scraper fallback when no Gemini
    // =========================================================================

    public function test_hero_includes_scraper_fallback_cambio_when_gemini_not_analyzed(): void
    {
        $fuente = Fuente::factory()->create(['nombre' => 'Scraper Fuente']);

        $scraper = Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'gemini_analyzed'      => false,
            'posibles_peps'        => 'Juan Pérez',
            'gemini_analisis_json' => null,
        ]);

        $snapshot = $this->service->getSnapshot();

        $this->assertNotNull($snapshot->hero);
        $this->assertSame($scraper->id, $snapshot->hero->id);
    }

    public function test_hero_excludes_cambio_when_gemini_analyzed_and_no_persona(): void
    {
        Fuente::factory()->create(['nombre' => 'Test Fuente']);

        // Gemini analyzed, no persona — should be excluded
        Cambio::factory()->create([
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'posibles_peps'        => 'Old scraper signal — should be ignored by Gemini verdict',
            'gemini_analisis_json' => [
                'riesgo'          => 'bajo',
                // no persona_nueva, no persona_removida
            ],
        ]);

        $snapshot = $this->service->getSnapshot();

        $this->assertNull($snapshot->hero);
    }

    // =========================================================================
    // T22 — Hero score formula respects config weights
    // =========================================================================

    public function test_hero_score_uses_configured_weights(): void
    {
        // Override to custom weights to verify they matter
        config(['dashboard.hero_formula' => [
            'riesgo_alto_weight' => 10,
            'es_mae_weight'      => 1,
            'aging_divisor'      => 1,
        ]]);

        $fuente = Fuente::factory()->create();

        // cambio A: es_mae pero bajo riesgo
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now(),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Ana',
                'riesgo'        => 'bajo',
                'es_mae'        => true,
            ],
        ]);

        // cambio B: riesgo alto, no es_mae — should win with weight=10
        $cambioB = Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now(),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Carlos',
                'riesgo'        => 'alto',
                'es_mae'        => false,
            ],
        ]);

        // Rebuild service after config change
        Cache::flush();
        $service = new DashboardSummaryService($this->cache);
        $snapshot = $service->getSnapshot();

        $this->assertNotNull($snapshot->hero);
        $this->assertSame($cambioB->id, $snapshot->hero->id, 'Riesgo alto weight=10 must dominate es_mae weight=1');
    }

    // =========================================================================
    // T23 — Triage strip counts and sparklines
    // =========================================================================

    public function test_triage_strip_has_correct_counts(): void
    {
        $fuente = Fuente::factory()->create();

        // 2 pending alto
        Cambio::factory()->count(2)->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'alto', 'persona_nueva' => 'Test Person'],
        ]);

        // 1 pending medio
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'medio', 'persona_nueva' => 'Test Person'],
        ]);

        // 3 pending bajo
        Cambio::factory()->count(3)->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'bajo', 'persona_nueva' => 'Test Person'],
        ]);

        // 1 revisado — must NOT be counted
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => true,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'alto', 'persona_nueva' => 'Test Person'],
        ]);

        $snapshot = $this->service->getSnapshot();
        $triage   = $snapshot->triage;

        $this->assertSame(2, $triage->pendientes_alto);
        $this->assertSame(1, $triage->pendientes_medio);
        $this->assertSame(3, $triage->pendientes_bajo);
    }

    public function test_sin_leer_excluye_records_no_analizados_y_secundarios(): void
    {
        // 2 valid primaries — must count
        ResultadoScraping::factory()->count(2)->create([
            'leido'           => false,
            'descartado'      => false,
            'archivado_at'    => null,
            'gemini_analyzed' => true,
            'secundario_de'   => null,
        ]);

        // 1 unanalyzed — must NOT count
        ResultadoScraping::factory()->sinAnalizar()->create([
            'leido'           => false,
            'descartado'      => false,
            'archivado_at'    => null,
            'secundario_de'   => null,
        ]);

        // 1 secondary — must NOT count (needs a real primary FK first)
        $primaryForFk = ResultadoScraping::factory()->create([
            'leido'           => false,
            'descartado'      => false,
            'archivado_at'    => null,
            'gemini_analyzed' => true,
            'secundario_de'   => null,
        ]);
        ResultadoScraping::factory()->create([
            'leido'           => false,
            'descartado'      => false,
            'archivado_at'    => null,
            'gemini_analyzed' => true,
            'secundario_de'   => $primaryForFk->id,
        ]);

        $snapshot = $this->service->getSnapshot();

        // Expected: 2 valid primaries + 1 primaryForFk (which is also valid) = 3
        $this->assertSame(3, $snapshot->triage->sin_leer);
    }

    public function test_triage_sparklines_have_exactly_7_elements(): void
    {
        $snapshot = $this->service->getSnapshot();
        $triage   = $snapshot->triage;

        $this->assertCount(7, $triage->sparkline_alto);
        $this->assertCount(7, $triage->sparkline_medio);
        $this->assertCount(7, $triage->sparkline_bajo);
        $this->assertCount(7, $triage->sparkline_sin_leer);
    }

    public function test_triage_sparkline_reflects_recent_7_days_data(): void
    {
        $fuente = Fuente::factory()->create();

        // Create 2 "alto" cambios exactly 1 day ago
        Cambio::factory()->count(2)->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now()->subDays(1),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'alto', 'persona_nueva' => 'Test Person'],
        ]);

        // Create 1 "alto" cambio older than 7 days — must NOT appear in sparkline
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now()->subDays(10),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'alto', 'persona_nueva' => 'Test Person'],
        ]);

        $snapshot = $this->service->getSnapshot();
        $sparkline = $snapshot->triage->sparkline_alto;

        // sparkline[5] = yesterday (index 5 = 6th element = day -1)
        $this->assertSame(2, $sparkline[5], 'Yesterday slot must contain 2 cambios');
        // sparkline[6] = today (no alto today)
        $this->assertSame(0, $sparkline[6], 'Today slot must contain 0 cambios (none created today)');
    }

    // =========================================================================
    // T24 — Backlog aging count
    // =========================================================================

    public function test_backlog_count_only_includes_cambios_older_than_threshold(): void
    {
        config(['dashboard.backlog_aging_days' => 3]);
        $fuente = Fuente::factory()->create();

        // Old cambio (4 days ago) — must be counted
        Cambio::factory()->create([
            'fuente_id' => $fuente->id,
            'revisado'  => false,
            'fecha'     => now()->subDays(4),
        ]);

        // Recent cambio (1 day ago) — must NOT be counted
        Cambio::factory()->create([
            'fuente_id' => $fuente->id,
            'revisado'  => false,
            'fecha'     => now()->subDays(1),
        ]);

        // Revisado cambio (5 days old) — must NOT be counted
        Cambio::factory()->create([
            'fuente_id' => $fuente->id,
            'revisado'  => true,
            'fecha'     => now()->subDays(5),
        ]);

        $snapshot = $this->service->getSnapshot();

        $this->assertSame(1, $snapshot->backlog->pendientes_antiguos);
        $this->assertSame(3, $snapshot->backlog->dias_threshold);
    }

    // =========================================================================
    // T25 — Recent discoveries: top PEPs from ResultadoScraping last 24h
    // =========================================================================

    public function test_recent_discoveries_returns_top_peps_last_24h(): void
    {
        // High-confidence PEP from 12h ago — must appear
        $highConf = ResultadoScraping::factory()->create([
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_confianza' => 90,
            'fecha_encontrado' => now()->subHours(12),
            'gemini_nombre'    => 'Juan García',
            'gemini_cargo'     => 'Ministro',
            'gemini_categoria' => 'PEP',
        ]);

        // Low-confidence PEP from 12h ago — must NOT appear (below threshold 80)
        ResultadoScraping::factory()->create([
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_confianza' => 50,
            'fecha_encontrado' => now()->subHours(12),
        ]);

        // High-confidence PEP but older than 24h — must NOT appear
        ResultadoScraping::factory()->create([
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_confianza' => 95,
            'fecha_encontrado' => now()->subHours(30),
        ]);

        $snapshot  = $this->service->getSnapshot();
        $peps      = $snapshot->discoveries->top_peps;

        $this->assertCount(1, $peps);
        $this->assertSame($highConf->id, $peps[0]->id);
        $this->assertSame('Juan García', $peps[0]->nombre);
    }

    public function test_recent_discoveries_returns_top_risk_cambios_last_24h(): void
    {
        $fuente = Fuente::factory()->create(['nombre' => 'Fuente Risk']);

        // Recent high-risk cambio — must appear
        $riskCambio = Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now()->subHours(6),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'alto', 'es_mae' => false],
        ]);

        // Old high-risk cambio — must NOT appear (>24h)
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now()->subHours(30),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'alto', 'es_mae' => false],
        ]);

        // Low risk cambio (recent) — must NOT appear
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'fecha'                => now()->subHours(3),
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['riesgo' => 'bajo', 'es_mae' => false],
        ]);

        $snapshot = $this->service->getSnapshot();
        $cambios  = $snapshot->discoveries->top_cambios;

        $this->assertCount(1, $cambios);
        $this->assertSame($riskCambio->id, $cambios[0]->id);
    }

    // =========================================================================
    // T26 — Ultima actividad revisada
    // =========================================================================

    public function test_ultima_actividad_revisada_is_null_when_no_revisado(): void
    {
        $snapshot = $this->service->getSnapshot();

        $this->assertNull($snapshot->ultima_actividad_revisada);
    }

    public function test_ultima_actividad_revisada_returns_latest_fecha_of_revisado_cambios(): void
    {
        $fuente = Fuente::factory()->create();

        $older = Cambio::factory()->create([
            'fuente_id' => $fuente->id,
            'revisado'  => true,
            'fecha'     => now()->subDays(2),
        ]);

        $newer = Cambio::factory()->create([
            'fuente_id' => $fuente->id,
            'revisado'  => true,
            'fecha'     => now()->subHours(3),
        ]);

        // Non-revisado: must NOT affect result
        Cambio::factory()->create([
            'fuente_id' => $fuente->id,
            'revisado'  => false,
            'fecha'     => now(),
        ]);

        $snapshot = $this->service->getSnapshot();

        $this->assertNotNull($snapshot->ultima_actividad_revisada);
        $this->assertInstanceOf(\DateTimeImmutable::class, $snapshot->ultima_actividad_revisada);

        // Should match the newer revisado cambio's fecha
        $this->assertEqualsWithDelta(
            $newer->fecha->timestamp,
            $snapshot->ultima_actividad_revisada->getTimestamp(),
            5,
            'ultima_actividad_revisada must match the newest revisado cambio'
        );
    }

    // =========================================================================
    // T27 — Cache: hit returns same DTO without re-querying
    // =========================================================================

    public function test_cache_hit_returns_same_dto_on_second_call(): void
    {
        $fuente = Fuente::factory()->create();
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Ana', 'riesgo' => 'alto', 'es_mae' => false],
        ]);

        $first  = $this->service->getSnapshot();
        $second = $this->service->getSnapshot();

        // Both calls must return valid DTOs with same hero ID
        $this->assertNotNull($first->hero);
        $this->assertNotNull($second->hero);
        $this->assertSame($first->hero->id, $second->hero->id);
    }

    // =========================================================================
    // T28 — Cache bust clears summary key
    // =========================================================================

    public function test_bust_clears_summary_cache(): void
    {
        $fuente = Fuente::factory()->create();
        Cambio::factory()->create([
            'fuente_id'            => $fuente->id,
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Marco', 'riesgo' => 'alto', 'es_mae' => false],
        ]);

        // Prime cache
        $before = $this->service->getSnapshot();
        $this->assertNotNull($before->hero);

        // Mark all as revisado (so next query returns null hero)
        Cambio::query()->update(['revisado' => true]);

        // Without bust: still returns cached hero
        $cached = $this->service->getSnapshot();
        $this->assertNotNull($cached->hero, 'Before bust, cache must return stale hero');

        // After bust: re-queries and sees no hero
        $this->service->bust();
        $fresh = $this->service->getSnapshot();

        $this->assertNull($fresh->hero, 'After bust, hero must be null (all cambios revisado)');
    }

    // =========================================================================
    // T29 — Empty data: all-zeros snapshot, null hero, null ultima actividad
    // =========================================================================

    public function test_empty_database_returns_safe_zero_snapshot(): void
    {
        $snapshot = $this->service->getSnapshot();

        $this->assertNull($snapshot->hero);
        $this->assertSame(0, $snapshot->triage->pendientes_alto);
        $this->assertSame(0, $snapshot->triage->pendientes_medio);
        $this->assertSame(0, $snapshot->triage->pendientes_bajo);
        $this->assertSame(0, $snapshot->triage->sin_leer);
        $this->assertSame(0, $snapshot->backlog->pendientes_antiguos);
        $this->assertEmpty($snapshot->discoveries->top_peps);
        $this->assertEmpty($snapshot->discoveries->top_cambios);
        $this->assertNull($snapshot->ultima_actividad_revisada);
    }
}
