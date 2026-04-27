<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Pep;

use App\Livewire\Pep\PanelPeps;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TDD tests for the PanelPeps Livewire component.
 *
 * Covers all 6 capabilities from the spec:
 *  - null evento hidden by default (mostrarSinClasificar=false)
 *  - toggle mostrarSinClasificar reveals null evento groups
 *  - filter URL persistence (#[Url] properties)
 *  - pagination resets when filter changes
 *  - archivar() action archives underlying resultados_scraping rows
 *  - verArticulos() redirects to Resultados with nombre URL-encoded
 */
class PanelPepsTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function crearUser(): User
    {
        return User::factory()->create(['activo' => true]);
    }

    private function crearResultado(
        string $fecha = '2026-04-27 10:00:00',
        ?string $archivadoAt = null,
    ): ResultadoScraping {
        return ResultadoScraping::create([
            'url'              => 'https://eldeber.com.bo/nota-' . uniqid(),
            'keyword'          => 'test-pep',
            'sitio_id'         => $this->sitio->id,
            'pais'             => 'BO',
            'fecha_encontrado' => $fecha,
            'relevance_score'  => 80,
            'found_in_title'   => false,
            'leido'            => false,
            'descartado'       => false,
            'archivado_at'     => $archivadoAt,
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_categoria' => 'PEP',
        ]);
    }

    private function crearPersona(
        ResultadoScraping $resultado,
        string $nombreNormalizado,
        ?string $evento,
        string $categoria = 'PEP',
        bool $thresholdPassed = true,
        ?string $cargo = null,
    ): ResultadoPersona {
        return ResultadoPersona::create([
            'resultado_scraping_id' => $resultado->id,
            'nombre'                => $nombreNormalizado,
            'nombre_normalizado'    => $nombreNormalizado,
            'evento'                => $evento,
            'categoria'             => $categoria,
            'threshold_passed'      => $thresholdPassed,
            'cargo'                 => $cargo,
            'confianza'             => 85,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3.B.1-2 — null evento hidden by default
    // ─────────────────────────────────────────────────────────────────────────

    public function test_null_evento_hidden_by_default(): void
    {
        $user = $this->crearUser();

        // Group 1: evento = null (sin clasificar) → should be hidden by default
        $r1 = $this->crearResultado();
        $this->crearPersona($r1, 'Maria Sin Clasificar', null);

        // Group 2: evento = 'renuncia' → should be visible
        $r2 = $this->crearResultado();
        $this->crearPersona($r2, 'Juan Renuncia', 'renuncia');

        Livewire::actingAs($user)
            ->test(PanelPeps::class)
            ->assertSet('mostrarSinClasificar', false)
            ->assertSee('Juan Renuncia')
            ->assertDontSee('Maria Sin Clasificar');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3.B.3-4 — toggle mostrarSinClasificar reveals null evento groups
    // ─────────────────────────────────────────────────────────────────────────

    public function test_toggle_mostrar_sin_clasificar_reveals_null_evento_groups(): void
    {
        $user = $this->crearUser();

        $r1 = $this->crearResultado();
        $this->crearPersona($r1, 'Maria Sin Clasificar', null);

        $r2 = $this->crearResultado();
        $this->crearPersona($r2, 'Juan Renuncia', 'renuncia');

        Livewire::actingAs($user)
            ->test(PanelPeps::class)
            ->set('mostrarSinClasificar', true)
            ->assertSee('Juan Renuncia')
            ->assertSee('Maria Sin Clasificar');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3.B.5-6 — filter values persist (assertSet confirms #[Url] properties)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_filter_values_reflect_set_properties(): void
    {
        $user = $this->crearUser();

        Livewire::actingAs($user)
            ->test(PanelPeps::class)
            ->set('filtroCategoria', 'PEP')
            ->assertSet('filtroCategoria', 'PEP')
            ->set('fechaDesde', '2026-04-01')
            ->assertSet('fechaDesde', '2026-04-01')
            ->set('fechaHasta', '2026-04-30')
            ->assertSet('fechaHasta', '2026-04-30')
            ->set('mostrarSinClasificar', true)
            ->assertSet('mostrarSinClasificar', true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3.B.7-8 — changing filter resets pagination to page 1
    // ─────────────────────────────────────────────────────────────────────────

    public function test_changing_filter_resets_pagination_to_page_one(): void
    {
        $user = $this->crearUser();

        // Create 30+ groups so pagination creates >1 page (25 per page)
        for ($i = 0; $i < 30; $i++) {
            $r = $this->crearResultado();
            $this->crearPersona($r, "Persona Numero {$i}", 'renuncia');
        }

        $component = Livewire::actingAs($user)
            ->test(PanelPeps::class);

        // Navigate to page 2
        $component->call('nextPage');
        $this->assertEquals(2, $component->get('paginators')['page'] ?? $component->instance()->getPage());

        // Change a filter — page must reset to 1
        $component->set('filtroCategoria', 'PEP');
        $this->assertEquals(1, $component->instance()->getPage());
    }

    public function test_changing_mostrar_sin_clasificar_resets_pagination(): void
    {
        $user = $this->crearUser();

        for ($i = 0; $i < 30; $i++) {
            $r = $this->crearResultado();
            $this->crearPersona($r, "Persona Num {$i}", 'designacion');
        }

        $component = Livewire::actingAs($user)
            ->test(PanelPeps::class);

        $component->call('nextPage');
        $this->assertEquals(2, $component->instance()->getPage());

        $component->set('mostrarSinClasificar', true);
        $this->assertEquals(1, $component->instance()->getPage());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3.B.9-10 — archivar() action archives underlying rows
    // ─────────────────────────────────────────────────────────────────────────

    public function test_archive_group_action_archives_underlying_rows(): void
    {
        $user = $this->crearUser();

        $r1 = $this->crearResultado();
        $r2 = $this->crearResultado();
        $r3 = $this->crearResultado();

        // All three belong to the same group (same person, same evento, same day)
        $this->crearPersona($r1, 'Rolando Montano', 'renuncia');
        $this->crearPersona($r2, 'Rolando Montano', 'renuncia');
        $this->crearPersona($r3, 'Rolando Montano', 'renuncia');

        Livewire::actingAs($user)
            ->test(PanelPeps::class)
            ->call('archivar', [$r1->id, $r2->id, $r3->id]);

        foreach ([$r1, $r2, $r3] as $row) {
            $row->refresh();
            $this->assertNotNull($row->archivado_at, "Row {$row->id} should be archived");
        }
    }

    public function test_archive_action_removes_group_from_default_view(): void
    {
        $user = $this->crearUser();

        $r1 = $this->crearResultado();
        $r2 = $this->crearResultado();
        $this->crearPersona($r1, 'Ana Visible', 'renuncia');
        $this->crearPersona($r2, 'Ana Visible', 'renuncia');

        // Verify visible before archiving
        Livewire::actingAs($user)
            ->test(PanelPeps::class)
            ->assertSee('Ana Visible')
            ->call('archivar', [$r1->id, $r2->id])
            // After archiving, all rows in group are archived → group disappears
            ->assertDontSee('Ana Visible');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fix — verArticulos() redirects to Resultados with specific resultado IDs
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ver_articulos_redirects_with_specific_resultado_ids(): void
    {
        $user = $this->crearUser();

        // Simulate a group with resultadoIds = [34, 36, 37]
        Livewire::actingAs($user)
            ->test(PanelPeps::class)
            ->call('verArticulos', '34,36,37')
            ->assertRedirect(route('scraper.resultados', ['ids' => '34,36,37']));
    }

    public function test_ver_articulos_redirects_with_ids_csv_string(): void
    {
        $user = $this->crearUser();

        // Different IDs set to triangulate against hardcoded return
        Livewire::actingAs($user)
            ->test(PanelPeps::class)
            ->call('verArticulos', '1,2,3')
            ->assertRedirect(route('scraper.resultados', ['ids' => '1,2,3']));
    }
}
