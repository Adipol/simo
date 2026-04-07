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
            'url' => 'https://example.com',
            'nombre' => 'Example',
            'pais' => 'BO',
            'activo' => true,
        ]);
    }

    private function createAnalyzedResultado(SitioWeb $sitio): ResultadoScraping
    {
        return ResultadoScraping::create([
            'url' => 'https://example.com/article-1',
            'keyword' => 'test keyword',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre' => 'Juan Perez',
            'gemini_cargo' => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo' => 'Es un PEP activo identificado por Gemini.',
        ]);
    }

    public function test_ver_analisis_button_sets_ver_analisis_id(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->assertSet('verAnalisisId', null)
            ->set('verAnalisisId', $resultado->id)
            ->assertSet('verAnalisisId', $resultado->id);
    }

    public function test_modal_shows_correct_data_when_open(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSee('Análisis Gemini')
            ->assertSee('Juan Perez')
            ->assertSee('Ministro')
            ->assertSee('85%')
            ->assertSee('Es un PEP activo identificado por Gemini.');
    }

    public function test_close_button_clears_ver_analisis_id(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSet('verAnalisisId', $resultado->id)
            ->set('verAnalisisId', null)
            ->assertSet('verAnalisisId', null);
    }

    public function test_backdrop_click_closes_modal(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id)
            ->assertSet('verAnalisisId', $resultado->id)
            ->call('$set', 'verAnalisisId', null)
            ->assertSet('verAnalisisId', null);
    }

    public function test_modal_not_rendered_when_ver_analisis_id_is_null(): void
    {
        $sitio = $this->createSitio();
        $this->createAnalyzedResultado($sitio);

        Livewire::test(Resultados::class)
            ->assertSet('verAnalisisId', null)
            ->assertDontSee('Análisis Gemini');
    }

    public function test_unanalyzed_result_does_not_show_ver_analisis_button(): void
    {
        $sitio = $this->createSitio();
        ResultadoScraping::create([
            'url' => 'https://example.com/article-1',
            'keyword' => 'unanalyzed keyword',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => false,
        ]);

        Livewire::test(Resultados::class)
            ->assertSee('unanalyzed keyword')
            ->assertDontSee('Ver análisis');
    }
}
