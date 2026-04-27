<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TDD tests for the filtroIds URL parameter on the Resultados component.
 *
 * Verifies that passing ?ids=34,36,37 filters the list to ONLY those
 * specific ResultadoScraping IDs, overriding the broad busqueda behaviour.
 */
class ResultadosFiltroIdsTest extends TestCase
{
    use RefreshDatabase;

    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.gemini.enabled' => false]);

        $this->sitio = SitioWeb::create([
            'url'    => 'https://eldeber.com.bo',
            'nombre' => 'El Deber',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function crearUser(): User
    {
        return User::factory()->create(['activo' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function crearResultado(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'              => 'https://eldeber.com.bo/nota-' . uniqid(),
            'keyword'          => 'test',
            'sitio_id'         => $this->sitio->id,
            'pais'             => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'found_in_title'   => false,
            'leido'            => false,
            'descartado'       => false,
            'gemini_analyzed'  => false,
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — filtroIds filters to specific IDs only
    // ─────────────────────────────────────────────────────────────────────────

    public function test_filtro_ids_param_filters_to_specific_resultado_scraping_ids(): void
    {
        $user = $this->crearUser();

        // Create 5 resultados with known distinct keywords for visibility
        $r1 = $this->crearResultado(['titulo' => 'Articulo Uno']);
        $r2 = $this->crearResultado(['titulo' => 'Articulo Dos']);
        $r3 = $this->crearResultado(['titulo' => 'Articulo Tres']);
        $r4 = $this->crearResultado(['titulo' => 'Articulo Cuatro']);
        $r5 = $this->crearResultado(['titulo' => 'Articulo Cinco']);

        // Set filtroIds to IDs 2 and 4 from the actual DB IDs
        $ids = implode(',', [$r2->id, $r4->id]);

        Livewire::actingAs($user)
            ->test(Resultados::class, ['filtroIds' => $ids])
            ->assertSee('Articulo Dos')
            ->assertSee('Articulo Cuatro')
            ->assertDontSee('Articulo Uno')
            ->assertDontSee('Articulo Tres')
            ->assertDontSee('Articulo Cinco');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — filtroIds persists in URL via #[Url(as: 'ids')]
    // ─────────────────────────────────────────────────────────────────────────

    public function test_filtro_ids_param_persists_in_url(): void
    {
        $user = $this->crearUser();

        $r1 = $this->crearResultado();
        $r2 = $this->crearResultado();

        $ids = "{$r1->id},{$r2->id}";

        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->set('filtroIds', $ids)
            ->assertSet('filtroIds', $ids);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — filtroIds combines with filtroGemini (intersection)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_filtro_ids_combines_with_other_filters(): void
    {
        $user = $this->crearUser();

        // PEP-classified resultado — should appear (matches ids AND filtroGemini=pep)
        $rPep = $this->crearResultado([
            'titulo'           => 'Articulo PEP Match',
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_categoria' => 'PEP',
        ]);

        // Non-PEP resultado — same IDs range but filtered out by filtroGemini
        $rNoPep = $this->crearResultado([
            'titulo'           => 'Articulo No PEP',
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => false,
            'gemini_categoria' => null,
        ]);

        // Third resultado entirely outside the IDs filter
        $rOutside = $this->crearResultado([
            'titulo'           => 'Articulo Outside',
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_categoria' => 'PEP',
        ]);

        // Filter: IDs = rPep + rNoPep, gemini = pep → only rPep survives
        $ids = implode(',', [$rPep->id, $rNoPep->id]);

        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->set('filtroIds', $ids)
            ->set('filtroGemini', 'pep')
            ->assertSee('Articulo PEP Match')
            ->assertDontSee('Articulo No PEP')
            ->assertDontSee('Articulo Outside');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — non-numeric IDs in filtroIds are safely ignored
    // ─────────────────────────────────────────────────────────────────────────

    public function test_filtro_ids_ignores_non_numeric_values(): void
    {
        $user = $this->crearUser();

        $r1 = $this->crearResultado(['titulo' => 'Articulo Valido']);
        $r2 = $this->crearResultado(['titulo' => 'Articulo Dos Valido']);

        // Mix valid IDs with garbage — should use valid IDs, ignore garbage
        $ids = "{$r1->id},abc,{$r2->id},;;,99999999";

        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->set('filtroIds', $ids)
            // Valid IDs in range → visible
            ->assertSee('Articulo Valido')
            ->assertSee('Articulo Dos Valido');
        // Note: 99999999 is a non-existent ID — test confirms no error thrown
    }
}
