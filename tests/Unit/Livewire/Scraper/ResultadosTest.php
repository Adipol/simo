<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
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

        // Prevent observer from dispatching Gemini jobs during tests
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

    private function createResultado(SitioWeb $sitio, array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article-1',
            'keyword' => 'test keyword',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    // ─── Task 3.1: buildQuery() filter logic ───

    public function test_filtro_gemini_empty_returns_all_results(): void
    {
        $sitio = $this->createSitio();
        $this->createResultado($sitio, ['keyword' => 'unanalyzed', 'gemini_analyzed' => false]);
        $this->createResultado($sitio, ['keyword' => 'pep', 'gemini_analyzed' => true, 'gemini_is_pep' => true, 'gemini_categoria' => 'PEP']);
        $this->createResultado($sitio, ['keyword' => 'not_pep', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);

        Livewire::test(Resultados::class)
            ->assertSet('filtroGemini', '')
            ->assertSee('unanalyzed')
            ->assertSee('pep')
            ->assertSee('not_pep');
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

    public function test_filtro_gemini_pep_returns_only_pep_confirmed(): void
    {
        $sitio = $this->createSitio();
        $this->createResultado($sitio, ['keyword' => 'pep_item', 'gemini_analyzed' => true, 'gemini_is_pep' => true, 'gemini_categoria' => 'PEP']);
        $this->createResultado($sitio, ['keyword' => 'opi_item', 'gemini_analyzed' => true, 'gemini_is_pep' => true, 'gemini_categoria' => 'OPI']);
        $this->createResultado($sitio, ['keyword' => 'not_pep_item', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);
        $this->createResultado($sitio, ['keyword' => 'pending_item', 'gemini_analyzed' => false]);

        Livewire::test(Resultados::class)
            ->set('filtroGemini', 'pep')
            ->assertSee('pep_item')
            ->assertDontSee('opi_item')
            ->assertDontSee('not_pep_item')
            ->assertDontSee('pending_item');
    }

    public function test_filtro_gemini_opi_returns_only_opi_confirmed(): void
    {
        $sitio = $this->createSitio();
        $this->createResultado($sitio, ['keyword' => 'opi_item', 'gemini_analyzed' => true, 'gemini_is_pep' => true, 'gemini_categoria' => 'OPI']);
        $this->createResultado($sitio, ['keyword' => 'pep_item', 'gemini_analyzed' => true, 'gemini_is_pep' => true, 'gemini_categoria' => 'PEP']);
        $this->createResultado($sitio, ['keyword' => 'not_pep_item', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);

        Livewire::test(Resultados::class)
            ->set('filtroGemini', 'opi')
            ->assertSee('opi_item')
            ->assertDontSee('pep_item')
            ->assertDontSee('not_pep_item');
    }

    public function test_filtro_gemini_not_pep_returns_only_non_pep_analyzed(): void
    {
        $sitio = $this->createSitio();
        $this->createResultado($sitio, ['keyword' => 'zznpepitem', 'gemini_analyzed' => true, 'gemini_is_pep' => false]);
        $this->createResultado($sitio, ['keyword' => 'zzpepconf', 'gemini_analyzed' => true, 'gemini_is_pep' => true, 'gemini_categoria' => 'PEP']);
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
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre' => 'Juan Perez',
            'gemini_cargo' => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo' => 'Es un PEP activo',
        ]);

        $component = Livewire::test(Resultados::class)
            ->set('verAnalisisId', $resultado->id);

        $component->assertSet('verAnalisisId', $resultado->id);

        $analisis = $component->instance()->resultadoAnalisis;
        $this->assertNotNull($analisis);
        $this->assertEquals($resultado->id, $analisis->id);
        $this->assertEquals('Juan Perez', $analisis->gemini_nombre);
    }

    public function test_resultado_analisis_returns_null_when_id_points_to_nonexistent_record(): void
    {
        $component = Livewire::test(Resultados::class)
            ->set('verAnalisisId', 99999);

        $this->assertNull($component->instance()->resultadoAnalisis);
    }
}
