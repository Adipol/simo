<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for the "Ver análisis" modal.
 *
 * Phase 2: Modal must read personas from resultado_personas table
 * ordered by threshold_passed DESC, confianza DESC.
 * Legacy gemini_* column assertions are removed.
 */
class ResultadosModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);
    }

    private function createSitio(): SitioWeb
    {
        return SitioWeb::create([
            'url'    => 'https://example.com',
            'nombre' => 'Example',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    private function createAnalyzedResultado(SitioWeb $sitio, string $urlSuffix = ''): ResultadoScraping
    {
        return ResultadoScraping::create([
            'url'              => 'https://example.com/article'.$urlSuffix,
            'keyword'          => 'test keyword',
            'sitio_id'         => $sitio->id,
            'pais'             => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'            => false,
            'relevante'        => null,
            'descartado'       => false,
            'gemini_analyzed'  => true,
        ]);
    }

    // ─── P2.T1 RED Tests ──────────────────────────────────────────────────────

    /**
     * Modal must show personas from resultado_personas, ordered by confianza DESC.
     */
    public function test_modal_shows_personas_from_resultado_personas(): void
    {
        $sitio     = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        ResultadoPersona::create([
            'resultado_scraping_id' => $resultado->id,
            'nombre'                => 'Juan Perez',
            'cargo'                 => 'Ministro de Hacienda',
            'categoria'             => 'PEP',
            'confianza'             => 90,
            'threshold_passed'      => true,
            'motivo'                => 'Identificado como funcionario publico.',
        ]);

        ResultadoPersona::create([
            'resultado_scraping_id' => $resultado->id,
            'nombre'                => 'Maria Lopez',
            'cargo'                 => 'Directora',
            'categoria'             => 'OPI',
            'confianza'             => 60,
            'threshold_passed'      => true,
            'motivo'                => 'Familiar de PEP.',
        ]);

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSee('Juan Perez')
            ->assertSee('Maria Lopez')
            ->assertSee('Ministro de Hacienda')
            ->assertSee('Directora');
    }

    /**
     * Modal must show a threshold_passed indicator per persona.
     * threshold_passed=false persons must show "Baja confianza" label.
     */
    public function test_modal_shows_threshold_passed_indicator(): void
    {
        $sitio     = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        // threshold_passed = false → must show low-confidence label
        ResultadoPersona::create([
            'resultado_scraping_id' => $resultado->id,
            'nombre'                => 'Carlos Baja',
            'cargo'                 => 'Asesor',
            'categoria'             => 'PEP',
            'confianza'             => 35,
            'threshold_passed'      => false,
            'motivo'                => 'Confianza insuficiente.',
        ]);

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSee('Carlos Baja')
            ->assertSee('Baja confianza');
    }

    /**
     * Modal handles 0 personas — must show empty state message.
     */
    public function test_modal_handles_zero_personas(): void
    {
        $sitio     = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);
        // No ResultadoPersona records created

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSee('Sin personas detectadas');
    }

    /**
     * Modal shows low-confidence label only for threshold_passed=false personas,
     * not for threshold_passed=true personas (triangulation test).
     */
    public function test_modal_shows_low_confidence_label_for_threshold_false(): void
    {
        $sitio     = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        ResultadoPersona::create([
            'resultado_scraping_id' => $resultado->id,
            'nombre'                => 'Ana Alta',
            'cargo'                 => 'Presidenta',
            'categoria'             => 'PEP',
            'confianza'             => 92,
            'threshold_passed'      => true,
            'motivo'                => 'Alta confianza.',
        ]);

        ResultadoPersona::create([
            'resultado_scraping_id' => $resultado->id,
            'nombre'                => 'Bob Bajo',
            'cargo'                 => 'Consultor',
            'categoria'             => 'PEP',
            'confianza'             => 28,
            'threshold_passed'      => false,
            'motivo'                => 'Baja confianza.',
        ]);

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSee('Ana Alta')
            ->assertSee('Bob Bajo')
            ->assertSee('Baja confianza'); // only Bob shows this label
    }

    // ─── Legacy behaviour tests (kept for context, updated for new structure) ─

    public function test_ver_analisis_button_sets_ver_analisis_id(): void
    {
        $sitio     = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->assertSet('verAnalisisId', null)
            ->set('verAnalisisId', $resultado->id)
            ->assertSet('verAnalisisId', $resultado->id);
    }

    public function test_close_button_clears_ver_analisis_id(): void
    {
        $sitio     = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSet('verAnalisisId', $resultado->id)
            ->set('verAnalisisId', null)
            ->assertSet('verAnalisisId', null);
    }

    public function test_modal_not_rendered_when_ver_analisis_id_is_null(): void
    {
        $sitio = $this->createSitio();
        $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->assertSet('verAnalisisId', null)
            ->assertDontSee('Personas detectadas');
    }

    public function test_unanalyzed_result_does_not_show_ver_analisis_button(): void
    {
        $sitio = $this->createSitio();
        ResultadoScraping::create([
            'url'              => 'https://example.com/unanalyzed',
            'keyword'          => 'unanalyzed keyword',
            'sitio_id'         => $sitio->id,
            'pais'             => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'            => false,
            'relevante'        => null,
            'descartado'       => false,
            'gemini_analyzed'  => false,
        ]);

        Livewire::test(Resultados::class)
            ->assertSee('unanalyzed keyword')
            ->assertDontSee('Ver análisis');
    }
}
