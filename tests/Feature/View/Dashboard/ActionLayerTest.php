<?php

declare(strict_types=1);

namespace Tests\Feature\View\Dashboard;

use App\Services\Dashboard\DTOs\BacklogAgeDTO;
use App\Services\Dashboard\DTOs\HeroCardDTO;
use App\Services\Dashboard\DTOs\RecentDiscoveriesDTO;
use App\Services\Dashboard\DTOs\TriageStripDTO;
use App\Services\Dashboard\DTOs\DashboardSummaryDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T38 — RED tests for action-layer, hero-card, triage-strip, triage-card Blade components.
 */
class ActionLayerTest extends TestCase
{
    use RefreshDatabase;

    private function makeZeroTriage(): TriageStripDTO
    {
        $zeros = [0, 0, 0, 0, 0, 0, 0];

        return new TriageStripDTO(
            pendientes_alto: 0,
            pendientes_medio: 0,
            pendientes_bajo: 0,
            sin_leer: 0,
            sparkline_alto: $zeros,
            sparkline_medio: $zeros,
            sparkline_bajo: $zeros,
            sparkline_sin_leer: $zeros,
        );
    }

    private function makeTriageWithData(): TriageStripDTO
    {
        return new TriageStripDTO(
            pendientes_alto: 3,
            pendientes_medio: 7,
            pendientes_bajo: 2,
            sin_leer: 14,
            sparkline_alto: [0, 1, 2, 1, 3, 2, 3],
            sparkline_medio: [1, 2, 3, 4, 5, 6, 7],
            sparkline_bajo: [0, 0, 1, 0, 1, 2, 2],
            sparkline_sin_leer: [5, 4, 6, 8, 10, 12, 14],
        );
    }

    private function makeHeroCard(): HeroCardDTO
    {
        return new HeroCardDTO(
            id: 42,
            fuente_nombre: 'Diario Oficial AR',
            riesgo: 'alto',
            es_mae: true,
            dias_pendiente: 5,
            score: 12.5,
            accion_url: '/pep/cambios?id=42',
            fecha: new \DateTimeImmutable('2026-05-05 10:00:00'),
        );
    }

    private function makeZeroBacklog(): BacklogAgeDTO
    {
        return new BacklogAgeDTO(
            pendientes_antiguos: 0,
            dias_threshold: 3,
            mas_antiguo_dias: null,
        );
    }

    private function makeEmptyDiscoveries(): RecentDiscoveriesDTO
    {
        return new RecentDiscoveriesDTO(top_peps: [], top_cambios: []);
    }

    // ─── Hero card renders with risk badge and action link ───────────────────

    public function test_hero_card_renders_fuente_nombre(): void
    {
        $html = view('components.dashboard.hero-card', [
            'hero' => $this->makeHeroCard(),
        ])->render();

        $this->assertStringContainsString('Diario Oficial AR', $html);
    }

    public function test_hero_card_renders_revisar_ahora_link(): void
    {
        $hero = $this->makeHeroCard();
        $html = view('components.dashboard.hero-card', [
            'hero' => $hero,
        ])->render();

        $this->assertStringContainsString('Revisar ahora', $html);
        $this->assertStringContainsString('/pep/cambios?id=42', $html);
    }

    public function test_hero_card_renders_alto_risk_border(): void
    {
        $html = view('components.dashboard.hero-card', [
            'hero' => $this->makeHeroCard(),
        ])->render();

        // alto risk must have visual emphasis — rose border class applied
        $this->assertStringContainsString('border-rose-500', $html);
    }

    // ─── Null hero → celebratory empty state ────────────────────────────────

    public function test_hero_card_null_shows_todo_al_dia(): void
    {
        $html = view('components.dashboard.hero-card', [
            'hero' => null,
        ])->render();

        $this->assertStringContainsString('Todo al día', $html);
    }

    // ─── Triage strip shows counts ───────────────────────────────────────────

    public function test_triage_strip_shows_counts(): void
    {
        $html = view('components.dashboard.triage-strip', [
            'triage' => $this->makeTriageWithData(),
        ])->render();

        $this->assertStringContainsString('3', $html);  // alto count
        $this->assertStringContainsString('7', $html);  // medio count
        $this->assertStringContainsString('14', $html); // sin_leer count
    }

    // ─── Action layer composes hero + triage ─────────────────────────────────

    public function test_action_layer_renders_both_hero_and_triage(): void
    {
        $summary = new DashboardSummaryDTO(
            hero: $this->makeHeroCard(),
            triage: $this->makeTriageWithData(),
            backlog: $this->makeZeroBacklog(),
            discoveries: $this->makeEmptyDiscoveries(),
            ultima_actividad_revisada: null,
        );

        $html = view('components.dashboard.action-layer', [
            'summary' => $summary,
        ])->render();

        $this->assertStringContainsString('Diario Oficial AR', $html);
        $this->assertStringContainsString('Revisar ahora', $html);
        $this->assertStringContainsString('3', $html);
    }
}
