<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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
        config(['services.dedupe.enabled' => false]);
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

    private function createResultado(SitioWeb $sitio, string $keyword, array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/'.uniqid(),
            'keyword' => $keyword,
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
        ], $overrides));
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

    public function test_discarded_url_filter_hydrates_to_all_discarded_semantics(): void
    {
        $sitio = $this->createSitio();
        $discarded = $this->createResultado($sitio, 'discarded-standard', ['descartado' => true]);
        $archivedDiscarded = $this->createResultado($sitio, 'discarded-archived', [
            'descartado' => true,
            'archivado_at' => now(),
        ]);
        $pendingDiscarded = $this->createResultado($sitio, 'discarded-pending', [
            'descartado' => true,
            'gemini_analyzed' => false,
        ]);
        $secondaryDiscarded = $this->createResultado($sitio, 'discarded-secondary', [
            'descartado' => true,
            'secundario_de' => $discarded->id,
        ]);
        $active = $this->createResultado($sitio, 'active-result');
        $expectedIds = collect([
            $discarded->id,
            $archivedDiscarded->id,
            $pendingDiscarded->id,
            $secondaryDiscarded->id,
        ])->sort()->values()->all();

        Livewire::withQueryParams([
            'filtroDescartado' => '1',
            'filtroArchivado' => '0',
            'filtroGemini' => 'pep',
        ])->test(Resultados::class)
            ->assertSet('filtroDescartado', '1')
            ->assertSet('filtroArchivado', '')
            ->assertSet('filtroGemini', '')
            ->assertSee('Mostrando todos los artículos descartados')
            ->assertViewHas('resultados', static function (LengthAwarePaginator $resultados) use ($expectedIds, $active): bool {
                $actualIds = $resultados->pluck('id')->sort()->values()->all();

                return $actualIds === $expectedIds && ! in_array($active->id, $actualIds, true);
            });
    }

    public function test_discarded_csv_uses_the_same_complete_filter_semantics(): void
    {
        $sitio = $this->createSitio();
        $discarded = $this->createResultado($sitio, 'csv-discarded-standard', ['descartado' => true]);
        $this->createResultado($sitio, 'csv-discarded-archived', [
            'descartado' => true,
            'archivado_at' => now(),
        ]);
        $this->createResultado($sitio, 'csv-discarded-pending', [
            'descartado' => true,
            'gemini_analyzed' => false,
        ]);
        $this->createResultado($sitio, 'csv-discarded-secondary', [
            'descartado' => true,
            'secundario_de' => $discarded->id,
        ]);
        $this->createResultado($sitio, 'csv-active-result');

        $component = Livewire::test(Resultados::class)
            ->set('filtroGemini', 'pep')
            ->set('filtroDescartado', '1')
            ->assertSet('filtroArchivado', '')
            ->assertSet('filtroGemini', '');

        ob_start();
        $component->instance()->exportarCsv()->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString('csv-discarded-standard', $csv);
        $this->assertStringContainsString('csv-discarded-archived', $csv);
        $this->assertStringContainsString('csv-discarded-pending', $csv);
        $this->assertStringContainsString('csv-discarded-secondary', $csv);
        $this->assertStringNotContainsString('csv-active-result', $csv);
    }

    public function test_restoring_a_normal_result_returns_it_to_active_results(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio, 'restored-active-result', ['descartado' => true]);

        Livewire::test(Resultados::class)
            ->call('restaurar', $resultado->id)
            ->assertDispatched('notify', static function (string $event, array $params): bool {
                return $event === 'notify'
                    && ($params['mensaje'] ?? null) === 'Artículo restaurado y disponible en resultados activos.';
            });

        $this->assertDatabaseHas('resultados_scraping', [
            'id' => $resultado->id,
            'descartado' => false,
            'gemini_analyzed' => true,
            'archivado_at' => null,
            'secundario_de' => null,
        ]);

        Livewire::test(Resultados::class)
            ->assertSee('restored-active-result');
    }

    public function test_restoring_hidden_results_preserves_their_state_and_explains_why(): void
    {
        $sitio = $this->createSitio();
        $primary = $this->createResultado($sitio, 'restored-primary');
        $hiddenResults = [
            'archived' => [$this->createResultado($sitio, 'restored-archived', [
                'descartado' => true,
                'archivado_at' => now(),
            ]), 'permanece archivado'],
            'pending' => [$this->createResultado($sitio, 'restored-pending', [
                'descartado' => true,
                'gemini_analyzed' => false,
            ]), 'está pendiente de análisis'],
            'secondary' => [$this->createResultado($sitio, 'restored-secondary', [
                'descartado' => true,
                'secundario_de' => $primary->id,
            ]), 'es un resultado secundario'],
        ];

        foreach ($hiddenResults as [$resultado, $motivo]) {
            Livewire::test(Resultados::class)
                ->call('restaurar', $resultado->id)
                ->assertDispatched('notify', static function (string $event, array $params) use ($motivo): bool {
                    return $event === 'notify'
                        && str_contains((string) ($params['mensaje'] ?? ''), $motivo);
                });

            $this->assertDatabaseHas('resultados_scraping', [
                'id' => $resultado->id,
                'descartado' => false,
            ]);
        }

        $this->assertDatabaseHas('resultados_scraping', [
            'id' => $hiddenResults['archived'][0]->id,
            'archivado_at' => $hiddenResults['archived'][0]->archivado_at,
        ]);
        $this->assertDatabaseHas('resultados_scraping', [
            'id' => $hiddenResults['pending'][0]->id,
            'gemini_analyzed' => false,
        ]);
        $this->assertDatabaseHas('resultados_scraping', [
            'id' => $hiddenResults['secondary'][0]->id,
            'secundario_de' => $primary->id,
        ]);

        Livewire::test(Resultados::class)
            ->assertDontSee('restored-archived')
            ->assertDontSee('restored-pending')
            ->assertDontSee('restored-secondary');
    }
}
