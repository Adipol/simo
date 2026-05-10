<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests that assert the ABSENCE of scope-creep features
 * (panel-peps route, feedback buttons, confirmar PEP button).
 *
 * These tests are written RED first (before removal) and should
 * turn GREEN once Phase 1 cleanup is complete.
 */
class ResultadosRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);
    }

    public function test_panel_peps_route_returns_404(): void
    {
        $this->assertFalse(
            collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
                ->contains(fn ($route) => $route->getName() === 'scraper.panel-peps'),
            'Route scraper.panel-peps should not exist after Phase 1 cleanup'
        );
    }

    public function test_confirmar_pep_button_not_in_dom(): void
    {
        $sitio = SitioWeb::create([
            'url'    => 'https://example.com',
            'nombre' => 'Example',
            'pais'   => 'BO',
            'activo' => true,
        ]);

        ResultadoScraping::create([
            'url'             => 'https://example.com/article-1',
            'keyword'         => 'test keyword',
            'sitio_id'        => $sitio->id,
            'pais'            => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido'           => false,
            'relevante'       => null,
            'descartado'      => false,
            'gemini_analyzed' => true,
            'gemini_is_pep'   => false, // not pep — confirmar button would appear
        ]);

        Livewire::test(Resultados::class)
            ->assertDontSee('Confirmar PEP');
    }

    public function test_feedback_buttons_not_in_dom(): void
    {
        $sitio = SitioWeb::create([
            'url'    => 'https://example.com',
            'nombre' => 'Example',
            'pais'   => 'BO',
            'activo' => true,
        ]);

        ResultadoScraping::create([
            'url'              => 'https://example.com/article-1',
            'keyword'          => 'feedback keyword',
            'sitio_id'         => $sitio->id,
            'pais'             => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'            => false,
            'relevante'        => null,
            'descartado'       => false,
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre'    => 'Juan Perez',
            'gemini_cargo'     => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo'    => 'Es PEP',
        ]);

        Livewire::test(Resultados::class)
            ->assertDontSee('✓ Correcto')
            ->assertDontSee('✗ Incorrecto');
    }
}
