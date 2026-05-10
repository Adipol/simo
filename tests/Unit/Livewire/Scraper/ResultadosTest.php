<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ResultadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent observer from dispatching jobs during tests
        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);
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

    private function createResultado(SitioWeb $sitio, array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'              => 'https://example.com/article-'.uniqid(),
            'keyword'          => 'test keyword',
            'sitio_id'         => $sitio->id,
            'pais'             => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'            => false,
            'relevante'        => null,
            'descartado'       => false,
            'gemini_analyzed'  => false,
        ], $overrides));
    }

    // ─── Task 3.1: buildQuery() filter logic (D11 new behavior) ───

    /**
     * Default filter (filtroGemini='') now shows ONLY gemini_analyzed=true articles.
     * Unanalyzed articles are no longer shown in the default view.
     * (Design D11: default is "processed primaries only")
     */
    public function test_filtro_gemini_empty_shows_only_analyzed_results(): void
    {
        $sitio = $this->createSitio();
        $this->createResultado($sitio, ['keyword' => 'unanalyzed-kw', 'gemini_analyzed' => false]);
        $this->createResultado($sitio, ['keyword' => 'analyzed-kw', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);

        Livewire::test(Resultados::class)
            ->assertSet('filtroGemini', '')
            ->assertSee('analyzed-kw')
            ->assertDontSee('unanalyzed-kw');
    }

    public function test_filtro_gemini_pending_returns_only_unanalyzed(): void
    {
        $sitio = $this->createSitio();
        $this->createResultado($sitio, ['keyword' => 'pending_kw_xyz', 'gemini_analyzed' => false]);
        $this->createResultado($sitio, ['keyword' => 'analyzed_kw_xyz', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);

        Livewire::test(Resultados::class)
            ->set('filtroGemini', 'pending')
            ->assertSee('pending_kw_xyz')
            ->assertDontSee('analyzed_kw_xyz');
    }

    /**
     * Filter 'pep' now reads from resultado_personas (Design D8).
     * Articles are shown if they have a PEP persona with threshold_passed=true.
     */
    public function test_filtro_gemini_pep_returns_only_articles_with_pep_persona(): void
    {
        $sitio = $this->createSitio();

        $pepArticle = $this->createResultado($sitio, [
            'keyword'         => 'pep_item',
            'gemini_analyzed' => true,
            'gemini_is_pep'   => true,
        ]);
        ResultadoPersona::create([
            'resultado_scraping_id' => $pepArticle->id,
            'nombre'                => 'Test PEP',
            'categoria'             => 'PEP',
            'confianza'             => 85,
            'threshold_passed'      => true,
        ]);

        $opiArticle = $this->createResultado($sitio, [
            'keyword'         => 'opi_item',
            'gemini_analyzed' => true,
            'gemini_is_pep'   => true,
        ]);
        ResultadoPersona::create([
            'resultado_scraping_id' => $opiArticle->id,
            'nombre'                => 'Test OPI',
            'categoria'             => 'OPI',
            'confianza'             => 85,
            'threshold_passed'      => true,
        ]);

        $this->createResultado($sitio, ['keyword' => 'not_pep_item', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);

        Livewire::test(Resultados::class)
            ->set('filtroGemini', 'pep')
            ->assertSee('pep_item')
            ->assertDontSee('opi_item')
            ->assertDontSee('not_pep_item');
    }

    /**
     * Filter 'opi' now reads from resultado_personas (Design D8).
     */
    public function test_filtro_gemini_opi_returns_only_articles_with_opi_persona(): void
    {
        $sitio = $this->createSitio();

        $opiArticle = $this->createResultado($sitio, [
            'keyword'         => 'opi_item',
            'gemini_analyzed' => true,
            'gemini_is_pep'   => true,
        ]);
        ResultadoPersona::create([
            'resultado_scraping_id' => $opiArticle->id,
            'nombre'                => 'Test OPI',
            'categoria'             => 'OPI',
            'confianza'             => 85,
            'threshold_passed'      => true,
        ]);

        $pepArticle = $this->createResultado($sitio, [
            'keyword'         => 'pep_item',
            'gemini_analyzed' => true,
            'gemini_is_pep'   => true,
        ]);
        ResultadoPersona::create([
            'resultado_scraping_id' => $pepArticle->id,
            'nombre'                => 'Test PEP',
            'categoria'             => 'PEP',
            'confianza'             => 85,
            'threshold_passed'      => true,
        ]);

        $this->createResultado($sitio, ['keyword' => 'not_pep_item', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);

        Livewire::test(Resultados::class)
            ->set('filtroGemini', 'opi')
            ->assertSee('opi_item')
            ->assertDontSee('pep_item')
            ->assertDontSee('not_pep_item');
    }

    /**
     * Filter 'not_pep' shows analyzed articles with no threshold_passed personas.
     * Articles with confirmed PEP/OPI persona should be hidden.
     */
    public function test_filtro_gemini_not_pep_returns_only_non_pep_analyzed(): void
    {
        $sitio = $this->createSitio();

        $noPepArticle = $this->createResultado($sitio, ['keyword' => 'zznpepitem', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);

        $pepConfArticle = $this->createResultado($sitio, ['keyword' => 'zzpepconf', 'gemini_analyzed' => true, 'gemini_is_pep' => true]);
        ResultadoPersona::create([
            'resultado_scraping_id' => $pepConfArticle->id,
            'nombre'                => 'PEP confirmed',
            'categoria'             => 'PEP',
            'confianza'             => 85,
            'threshold_passed'      => true,
        ]);

        $this->createResultado($sitio, ['keyword' => 'zzpending', 'gemini_analyzed' => false]);

        Livewire::test(Resultados::class)
            ->set('filtroGemini', 'not_pep')
            ->assertSee('zznpepitem')
            ->assertDontSee('zzpepconf')
            ->assertDontSee('zzpending');
    }

    // ─── Task 3.2: resultadoAnalisis computed property ───

    public function test_resultado_analisis_returns_null_when_ver_analisis_id_is_null(): void
    {
        $component = Livewire::test(Resultados::class);
        $component->assertSet('verAnalisisId', null);
        $this->assertNull($component->instance()->resultadoAnalisis);
    }

    public function test_resultado_analisis_returns_correct_model_when_id_is_set(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio, [
            'gemini_analyzed' => true,
            'gemini_is_pep'   => true,
            'gemini_motivo'   => 'Es un PEP activo',
        ]);

        $component = Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id);

        $component->assertSet('verAnalisisId', $resultado->id);

        $analisis = $component->instance()->resultadoAnalisis;
        $this->assertNotNull($analisis);
        $this->assertEquals($resultado->id, $analisis->id);
    }

    public function test_resultado_analisis_returns_null_when_id_points_to_nonexistent_record(): void
    {
        $component = Livewire::test(Resultados::class)
            ->set('verAnalisisId', 99999);

        $this->assertNull($component->instance()->resultadoAnalisis);
    }
}
