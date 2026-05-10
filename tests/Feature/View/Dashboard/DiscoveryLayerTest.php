<?php

declare(strict_types=1);

namespace Tests\Feature\View\Dashboard;

use App\Services\Dashboard\DTOs\CambioSummary;
use App\Services\Dashboard\DTOs\PepHighConfidence;
use App\Services\Dashboard\DTOs\RecentDiscoveriesDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T40 — RED tests for discovery-layer, recent-pep-card, recent-cambio-card components.
 */
class DiscoveryLayerTest extends TestCase
{
    use RefreshDatabase;

    private function makePep(): PepHighConfidence
    {
        return new PepHighConfidence(
            id: 1,
            nombre: 'Juan Pérez',
            cargo: 'Ministro',
            pais: 'AR',
            confianza: 97.5,
            categoria: 'PEP',
            fecha: new \DateTimeImmutable('2026-05-10 10:00:00'),
        );
    }

    private function makeCambio(): CambioSummary
    {
        return new CambioSummary(
            id: 10,
            fuente_nombre: 'Diario Oficial',
            riesgo: 'alto',
            lineas_nuevas: 5,
            lineas_quitadas: 2,
            analisis_snippet: 'Se detectó nuevo cargo ministerial en el organigrama oficial.',
            fecha: new \DateTimeImmutable('2026-05-10 08:00:00'),
        );
    }

    // ─── PEP card renders name and confidence ────────────────────────────────

    public function test_recent_pep_card_renders_nombre(): void
    {
        $html = view('components.dashboard.recent-pep-card', [
            'pep' => $this->makePep(),
        ])->render();

        $this->assertStringContainsString('Juan Pérez', $html);
    }

    public function test_recent_pep_card_renders_confidence_bar(): void
    {
        $html = view('components.dashboard.recent-pep-card', [
            'pep' => $this->makePep(),
        ])->render();

        // Confidence percentage must appear
        $this->assertStringContainsString('97', $html);
    }

    public function test_recent_pep_card_renders_cargo_and_pais(): void
    {
        $html = view('components.dashboard.recent-pep-card', [
            'pep' => $this->makePep(),
        ])->render();

        $this->assertStringContainsString('Ministro', $html);
        $this->assertStringContainsString('AR', $html);
    }

    // ─── Cambio card renders source, risk badge, snippet ─────────────────────

    public function test_recent_cambio_card_renders_fuente(): void
    {
        $html = view('components.dashboard.recent-cambio-card', [
            'cambio' => $this->makeCambio(),
        ])->render();

        $this->assertStringContainsString('Diario Oficial', $html);
    }

    public function test_recent_cambio_card_renders_risk_badge(): void
    {
        $html = view('components.dashboard.recent-cambio-card', [
            'cambio' => $this->makeCambio(),
        ])->render();

        // The badge renders the risk level — component uses strtoupper()
        $this->assertStringContainsString('ALTO', $html);
    }

    public function test_recent_cambio_card_renders_snippet(): void
    {
        $html = view('components.dashboard.recent-cambio-card', [
            'cambio' => $this->makeCambio(),
        ])->render();

        $this->assertStringContainsString('cargo ministerial', $html);
    }

    // ─── Discovery layer composes both columns ───────────────────────────────

    public function test_discovery_layer_renders_both_columns(): void
    {
        $discoveries = new RecentDiscoveriesDTO(
            top_peps: [$this->makePep()],
            top_cambios: [$this->makeCambio()],
        );

        $html = view('components.dashboard.discovery-layer', [
            'discoveries' => $discoveries,
        ])->render();

        $this->assertStringContainsString('Juan Pérez', $html);
        $this->assertStringContainsString('Diario Oficial', $html);
    }

    // ─── Empty state shows teaching messages ─────────────────────────────────

    public function test_discovery_layer_empty_peps_shows_message(): void
    {
        $discoveries = new RecentDiscoveriesDTO(top_peps: [], top_cambios: []);

        $html = view('components.dashboard.discovery-layer', [
            'discoveries' => $discoveries,
        ])->render();

        $this->assertStringContainsString('sin personas detectadas', strtolower($html));
    }
}
