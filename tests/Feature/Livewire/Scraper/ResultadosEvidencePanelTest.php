<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ResultadosEvidencePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 6, 1)->startOfDay());
        Queue::fake();
        config(['services.gemini.enabled' => false, 'services.dedupe.enabled' => false]);
    }

    /** @param array<string, bool|int|string|null> $attributes */
    private function article(string $name, array $attributes = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.test/'.$name,
            'keyword' => $name,
            'titulo' => $name,
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'gemini_analyzed' => true,
            'leido' => false,
            'descartado' => false,
        ], $attributes));
    }

    public function test_default_is_expandable_table_with_informational_help_and_no_automatic_comparison(): void
    {
        $primary = $this->article('Table primary');
        $secondary = $this->article('Table secondary', ['secundario_de' => $primary->id]);
        $before = ResultadoScraping::orderBy('id')->get()->toArray();
        $component = Livewire::test(Resultados::class)
            ->assertViewHas('seleccionado', null)
            ->assertDontSeeHtml('id="evidence-panel"')
            ->assertSee('Comparar fuentes')->assertSee('Criterios propuestos · no ejecutados');
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//table[@data-testid="results-table"]')->length);
        $this->assertSame(5, $xpath->query('//table/thead/tr/th[@scope="col"]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-testid="table-sources"]')->length);
        $this->assertSame(0, $xpath->query('//summary//button | //summary//a | //summary//select')->length);
        $this->assertSame(1, $xpath->query('//details[@data-testid="criteria-help"]')->length);
        $this->assertSame(0, $xpath->query('//*[@role="tablist"]')->length);
        $component->call('seleccionarResultado', $primary->id)->assertSeeHtml('id="evidence-panel"')
            ->assertSee('Volver a la tabla')->call('cerrarComparacion')
            ->assertDontSeeHtml('id="evidence-panel"')->assertSet('seleccionadoId', null);
        $this->assertSame($before, ResultadoScraping::orderBy('id')->get()->toArray());
        Queue::assertNothingPushed();
    }

    public function test_comparison_preserves_page_filters_and_refuses_forged_method_ids(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->article('Kept-'.$i, ['fecha_encontrado' => now()->addMinutes($i)->toDateTimeString()]);
        }
        $oldest = ResultadoScraping::orderBy('id')->firstOrFail();
        Livewire::test(Resultados::class)->set('busqueda', 'Kept')->call('gotoPage', 2)
            ->call('seleccionarResultado', $oldest->id)->assertSet('paginators.page', 2)
            ->assertSet('busqueda', 'Kept')->assertSeeHtml('id="evidence-panel"')
            ->call('cerrarComparacion')->assertSet('paginators.page', 2)->assertSet('busqueda', 'Kept')
            ->call('seleccionarResultado', 999999)->assertSet('seleccionadoId', null)
            ->assertDontSeeHtml('id="evidence-panel"');
    }

    public function test_default_summaries_do_not_load_analysis_and_each_filter_closes_comparison(): void
    {
        $primary = $this->article('Summary', ['pais' => 'BO']);
        $component = Livewire::test(Resultados::class)
            ->assertViewHas('resultados', fn (\Illuminate\Pagination\LengthAwarePaginator $rows): bool => $rows->getCollection()->every(fn (ResultadoScraping $r): bool => ! $r->relationLoaded('personas')))
            ->assertViewHas('fuentesEvidencia', fn (\Illuminate\Support\Collection $sources): bool => $sources->isEmpty());
        foreach (['busqueda' => '', 'filtroPais' => 'BO', 'filtroCategoria' => '', 'filtroLeido' => '', 'filtroRelevante' => '', 'filtroDescartado' => '0', 'filtroArchivado' => '0', 'filtroGemini' => '', 'filtroIds' => ''] as $filter => $value) {
            $component->call('seleccionarResultado', $primary->id)->assertSeeHtml('id="evidence-panel"')
                ->set($filter, $value)->assertSet('seleccionadoId', null)->assertDontSeeHtml('id="evidence-panel"');
        }
    }

    public function test_compact_comparison_keeps_all_counterparts_and_full_context_available(): void
    {
        $primary = $this->article('Primary comparison', ['contexto' => 'Full primary context']);
        $second = $this->article('Second comparison', ['secundario_de' => $primary->id]);
        $third = $this->article('Third comparison', ['secundario_de' => $primary->id, 'contexto' => 'Full third context']);
        $html = Livewire::test(Resultados::class)->call('seleccionarResultado', $primary->id)->html();
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//*[@data-testid="source-comparison"]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-testid="counterpart-selector"]/option')->length);
        foreach ([$second, $third] as $source) {
            $this->assertSame(1, $xpath->query('//*[@data-testid="counterpart-selector"]/option[@value="'.$source->id.'"]')->length);
        }
        $this->assertSame(3, $xpath->query('//details[@data-testid="source-context"]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-testid="source-comparison"]/article[not(@hidden)]')->length);
        $this->assertStringContainsString('x-model="counterpart"', $html);
        $this->assertStringContainsString(':hidden="counterpart !==', $html);
        $this->assertStringContainsString('wire:key="evidence-group-'.$primary->id.'"', $html);
        $this->assertSame(1, $xpath->query('//dl[@data-testid="comparison-facts"]')->length);
        $this->assertSame(1, $xpath->query('//details//*[contains(text(), "Full third context")]')->length);
        $this->assertStringContainsString('simo-evidence-table', $html);
        $this->assertStringNotContainsString('lg:grid-cols-3', $html);
    }

    public function test_singleton_does_not_offer_a_fictional_counterpart(): void
    {
        $only = $this->article('Only source');
        Livewire::test(Resultados::class)->call('seleccionarResultado', $only->id)
            ->assertDontSeeHtml('data-testid="counterpart-selector"')
            ->assertSee('Sin contraparte registrada')
            ->assertSeeHtml('data-testid="comparison-facts"');
    }

    public function test_selected_group_shows_every_recorded_source_without_mutation(): void
    {
        $primary = $this->article('Primary');
        $this->article('Second-one', ['secundario_de' => $primary->id]);
        $this->article('Second-two', ['secundario_de' => $primary->id]);
        $other = $this->article('Other');
        $before = ResultadoScraping::orderBy('id')->get()->toArray();

        Livewire::test(Resultados::class)
            ->call('seleccionarResultado', $primary->id)
            ->assertSee('Fuentes vinculadas')
            ->assertSee('Second-one')->assertSee('Second-two')
            ->assertSee('Fecha de hallazgo')->assertSee('no acredita autoría')
            ->assertSee('Sin personas detectadas')
            ->call('seleccionarResultado', $other->id)
            ->assertViewHas('fuentesEvidencia', fn (\Illuminate\Support\Collection $sources): bool => $sources->pluck('id')->all() === [$other->id])
            ->call('cerrarComparacion')->assertViewHas('seleccionado', null);

        $this->assertSame($before, ResultadoScraping::orderBy('id')->get()->toArray());
        Queue::assertNothingPushed();
    }

    public function test_singleton_and_missing_data_are_explicit(): void
    {
        $only = $this->article('Singleton', ['titulo' => null]);
        Livewire::test(Resultados::class)->call('seleccionarResultado', $only->id)
            ->assertSee('Sin título registrado')->assertSee('Sitio no registrado')
            ->assertSee('Sin fuentes vinculadas')->assertSee('Sin personas detectadas');
    }

    public function test_forged_stale_secondary_and_filtered_selections_close_comparison(): void
    {
        $first = $this->article('Visible');
        $hidden = $this->article('Hidden', ['descartado' => true]);
        $secondary = $this->article('Secondary', ['secundario_de' => $first->id]);
        $component = Livewire::test(Resultados::class);
        foreach ([$hidden->id, $secondary->id, 999999] as $id) {
            $component->set('seleccionadoId', $id)
                ->assertDontSeeHtml('id="evidence-panel"')
                ->assertViewHas('seleccionado', null);
        }
        $component->call('seleccionarResultado', $first->id)->call('descartar', $first->id)
            ->assertSet('seleccionadoId', null)
            ->assertSee('Sin resultados para los filtros aplicados.')
            ->assertViewHas('seleccionado', null)
            ->call('restaurar', $first->id)->assertDontSeeHtml('id="evidence-panel"')
            ->set('filtroDescartado', '1')
            ->assertViewHas('seleccionado', null);
    }

    public function test_selection_is_limited_to_current_page_and_filter_intersection(): void
    {
        $records = collect();
        for ($i = 0; $i < 26; $i++) {
            $records->push($this->article('Page-'.$i, ['fecha_encontrado' => now()->addMinutes($i)->toDateTimeString()]));
        }
        $oldest = $records->first();
        Livewire::test(Resultados::class)
            ->set('seleccionadoId', $oldest->id)
            ->assertViewHas('seleccionado', null)
            ->call('gotoPage', 2)->assertViewHas('seleccionado', null)
            ->call('seleccionarResultado', $oldest->id)
            ->assertViewHas('seleccionado', fn (?ResultadoScraping $r): bool => $r?->id === $oldest->id)
            ->set('filtroIds', (string) $oldest->id)
            ->set('busqueda', 'not-matching')
            ->assertViewHas('seleccionado', null);
    }

    public function test_title_comparison_reports_literal_differences_without_event_claims(): void
    {
        $primary = $this->article('Primary-title');
        $this->article('Same', ['titulo' => 'Primary-title', 'secundario_de' => $primary->id]);
        $this->article('Different', ['secundario_de' => $primary->id]);
        $this->article('Missing', ['titulo' => null, 'secundario_de' => $primary->id]);
        Livewire::test(Resultados::class)->call('seleccionarResultado', $primary->id)
            ->assertSee('Título idéntico al primario')
            ->assertSee('Título diferente al primario')
            ->assertSee('Título no disponible para comparar')
            ->assertSee('no una validación del mismo evento');
    }

    public function test_filter_and_page_changes_close_comparison(): void
    {
        $first = $this->article('Newest', ['fecha_encontrado' => now()->addMinute()->toDateTimeString()]);
        $other = $this->article('Older');
        Livewire::test(Resultados::class)->call('seleccionarResultado', $other->id)
            ->set('filtroPais', 'BO')->assertSet('seleccionadoId', null)
            ->assertViewHas('seleccionado', null)
            ->call('seleccionarResultado', $other->id)->call('gotoPage', 1)
            ->assertSet('seleccionadoId', null)
            ->assertViewHas('seleccionado', null);
    }

    public function test_only_selected_group_loads_detail_relations_and_attributes_analysis(): void
    {
        $selected = $this->article('Selected', ['fecha_encontrado' => now()->addMinute()->toDateTimeString()]);
        $other = $this->article('Not-selected');
        $secondary = $this->article('Recorded-source', ['secundario_de' => $selected->id]);
        $secondary->personas()->create(['nombre' => 'Source Person', 'cargo' => 'Recorded Office', 'categoria' => 'OPI', 'confianza' => 85, 'threshold_passed' => true]);
        Livewire::test(Resultados::class)->call('seleccionarResultado', $selected->id)
            ->assertSee('Source Person')->assertSee('Recorded Office')
            ->assertSee('Análisis Gemini de este artículo')
            ->assertViewHas('resultados', fn (\Illuminate\Pagination\LengthAwarePaginator $rows): bool => ! $rows->getCollection()->firstWhere('id', $other->id)->relationLoaded('personas'))
            ->assertViewHas('fuentesEvidencia', fn (\Illuminate\Support\Collection $sources): bool => $sources->every(fn (ResultadoScraping $r): bool => $r->relationLoaded('sitio') && $r->relationLoaded('personas')));
    }

    public function test_evidence_escapes_text_and_does_not_activate_unsafe_source_links(): void
    {
        $primary = $this->article('Safe');
        $this->article('Unsafe', [
            'secundario_de' => $primary->id,
            'titulo' => '<script>alert(1)</script>',
            'url' => 'javascript:alert(1)',
        ]);
        Livewire::test(Resultados::class)->call('seleccionarResultado', $primary->id)
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->assertDontSeeHtml('href="javascript:alert(1)"')
            ->assertSee('Enlace no disponible');
    }

    public function test_pending_secondary_explains_its_recorded_primary_group(): void
    {
        $primary = $this->article('Recorded-primary');
        $secondary = $this->article('Pending-secondary', ['secundario_de' => $primary->id, 'gemini_analyzed' => false]);
        $this->article('Sibling', ['secundario_de' => $primary->id]);
        Livewire::test(Resultados::class)->set('filtroGemini', 'pending')
            ->call('seleccionarResultado', $secondary->id)
            ->assertSee('Recorded-primary')->assertSee('Sibling')->assertSee('Primario registrado');
    }
}
