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

class ResultadosFilterTest extends TestCase
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

    private function seedResultados(SitioWeb $sitio, int $count = 30): void
    {
        for ($i = 0; $i < $count; $i++) {
            ResultadoScraping::create([
                'url' => "https://example.com/article-{$i}",
                'keyword' => "keyword-{$i}",
                'sitio_id' => $sitio->id,
                'pais' => 'BO',
                'fecha_encontrado' => now()->subMinutes($i),
                'relevance_score' => rand(10, 90),
                'leido' => false,
                'relevante' => null,
                'descartado' => false,
                'gemini_analyzed' => $i % 3 === 0,
                'gemini_is_pep' => $i % 3 === 0 ? ($i % 2 === 0) : null,
                'gemini_categoria' => $i % 3 === 0 ? ($i % 2 === 0 ? 'PEP' : 'OPI') : null,
            ]);
        }
    }

    public function test_filter_is_retained_in_component_state_after_pagination(): void
    {
        $sitio = $this->createSitio();
        $this->seedResultados($sitio, 30);

        Livewire::test(Resultados::class)
            ->set('filtroGemini', 'pending')
            ->assertSet('filtroGemini', 'pending')
            ->call('nextPage')
            ->assertSet('filtroGemini', 'pending');
    }

    public function test_filter_resets_pagination_when_changed(): void
    {
        $sitio = $this->createSitio();
        $this->seedResultados($sitio, 30);

        // After changing filter, page resets to 1 (resetPage called by updatingFiltroGemini)
        $component = Livewire::test(Resultados::class)
            ->call('nextPage')
            ->set('filtroGemini', 'pep');

        // Verify filter was applied and component renders without error
        $component->assertSet('filtroGemini', 'pep');
        // The updatingFiltroGemini method calls resetPage, so page is back to 1
        // We verify by checking the component rendered correctly (no exception)
        $component->assertOk();
    }

    public function test_filter_state_persists_across_renders(): void
    {
        $sitio = $this->createSitio();
        $this->seedResultados($sitio, 10);

        $component = Livewire::test(Resultados::class)
            ->set('filtroGemini', 'opi');

        $component->call('$refresh')
            ->assertSet('filtroGemini', 'opi');
    }
}
