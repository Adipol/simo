<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\ResultadoScrapingQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P4.T8 — RED tests for refactored ResultadoScrapingQueryService.
 *
 * Verifies the new filter behavior per Design D8 + D11:
 *  - Default (filtroGemini='') shows only analyzed articles AND excludes secondaries
 *  - Filter 'pep' uses whereHas('personas', categoria=PEP, threshold_passed=true)
 *  - Filter 'opi' uses whereHas('personas', categoria=OPI, threshold_passed=true)
 *  - Filter 'not_pep' uses whereDoesntHave with threshold_passed=true
 *  - Secondaries are excluded from default view
 */
class ResultadoScrapingQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResultadoScrapingQueryService $service;
    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        $this->service = new ResultadoScrapingQueryService();

        $this->sitio = SitioWeb::create([
            'url'    => 'https://example.com',
            'nombre' => 'Example',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    private function makeArticle(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'              => 'https://example.com/article-'.uniqid(),
            'keyword'          => 'test',
            'sitio_id'         => $this->sitio->id,
            'pais'             => 'BO',
            'titulo'           => 'Test article',
            'contexto'         => '',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'            => false,
            'descartado'       => false,
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => false,
        ], $overrides));
    }

    private function addPersona(ResultadoScraping $article, array $overrides = []): ResultadoPersona
    {
        return ResultadoPersona::create(array_merge([
            'resultado_scraping_id' => $article->id,
            'nombre'                => 'Test Person',
            'categoria'             => 'PEP',
            'confianza'             => 85,
            'threshold_passed'      => true,
        ], $overrides));
    }

    // ─── Default filter excludes secondaries ──────────────────────────────────

    public function test_default_filter_excludes_secondary_articles(): void
    {
        $primary   = $this->makeArticle(['titulo' => 'Primary article keyword-primary']);
        $secondary = $this->makeArticle([
            'titulo'        => 'Secondary article keyword-secondary',
            'secundario_de' => $primary->id,
        ]);

        $results = $this->service->buildQuery()->pluck('id');

        $this->assertContains($primary->id, $results, 'Primary article must appear in default results');
        $this->assertNotContains($secondary->id, $results, 'Secondary article must NOT appear in default results');
    }

    // ─── Default filter shows only gemini_analyzed=true ───────────────────────

    public function test_default_filter_shows_only_analyzed_articles(): void
    {
        $analyzed   = $this->makeArticle(['gemini_analyzed' => true]);
        $unanalyzed = $this->makeArticle(['gemini_analyzed' => false]);

        $results = $this->service->buildQuery()->pluck('id');

        $this->assertContains($analyzed->id, $results, 'Analyzed article must appear in default results');
        $this->assertNotContains($unanalyzed->id, $results, 'Unanalyzed article must NOT appear in default results');
    }

    // ─── Filter PEP uses resultado_personas ───────────────────────────────────

    public function test_pep_filter_returns_articles_with_pep_persona_threshold_passed(): void
    {
        $pepArticle = $this->makeArticle();
        $this->addPersona($pepArticle, ['categoria' => 'PEP', 'threshold_passed' => true]);

        $noPersonaArticle = $this->makeArticle();

        $results = $this->service->buildQuery(filtroGemini: 'pep')->pluck('id');

        $this->assertContains($pepArticle->id, $results, 'Article with PEP persona (threshold=true) must appear in PEP filter');
        $this->assertNotContains($noPersonaArticle->id, $results, 'Article without personas must NOT appear in PEP filter');
    }

    public function test_pep_filter_excludes_articles_where_threshold_passed_is_false(): void
    {
        $lowConf = $this->makeArticle();
        $this->addPersona($lowConf, ['categoria' => 'PEP', 'threshold_passed' => false]);

        $results = $this->service->buildQuery(filtroGemini: 'pep')->pluck('id');

        $this->assertNotContains($lowConf->id, $results, 'PEP article with threshold_passed=false must NOT appear in PEP filter');
    }

    // ─── Filter OPI uses resultado_personas ───────────────────────────────────

    public function test_opi_filter_returns_articles_with_opi_persona_threshold_passed(): void
    {
        $opiArticle = $this->makeArticle();
        $this->addPersona($opiArticle, ['categoria' => 'OPI', 'threshold_passed' => true]);

        $pepArticle = $this->makeArticle();
        $this->addPersona($pepArticle, ['categoria' => 'PEP', 'threshold_passed' => true]);

        $results = $this->service->buildQuery(filtroGemini: 'opi')->pluck('id');

        $this->assertContains($opiArticle->id, $results, 'Article with OPI persona (threshold=true) must appear in OPI filter');
        $this->assertNotContains($pepArticle->id, $results, 'Article with PEP persona must NOT appear in OPI filter');
    }

    // ─── Filter not_pep ───────────────────────────────────────────────────────

    public function test_not_pep_filter_returns_articles_without_threshold_passed_personas(): void
    {
        $noPepArticle = $this->makeArticle(['gemini_is_pep' => false]);

        $pepArticle = $this->makeArticle(['gemini_is_pep' => true]);
        $this->addPersona($pepArticle, ['categoria' => 'PEP', 'threshold_passed' => true]);

        $results = $this->service->buildQuery(filtroGemini: 'not_pep')->pluck('id');

        $this->assertContains($noPepArticle->id, $results, 'Article without PEP personas must appear in not_pep filter');
        $this->assertNotContains($pepArticle->id, $results, 'Article with PEP persona (threshold=true) must NOT appear in not_pep filter');
    }

    // ─── gemini_categoria not referenced (behavior test) ─────────────────────

    public function test_pep_filter_works_even_when_gemini_categoria_is_null(): void
    {
        // Article where legacy column is NULL but resultado_personas has PEP
        $article = $this->makeArticle([
            'gemini_categoria' => null,
            'gemini_is_pep'    => null,
        ]);
        $this->addPersona($article, ['categoria' => 'PEP', 'threshold_passed' => true]);

        $results = $this->service->buildQuery(filtroGemini: 'pep')->pluck('id');

        $this->assertContains(
            $article->id,
            $results,
            'PEP filter must work based on resultado_personas, regardless of gemini_categoria column'
        );
    }

    // ─── secondaries_count is eager loaded ───────────────────────────────────

    public function test_default_query_includes_secondaries_count(): void
    {
        $primary   = $this->makeArticle();
        $secondary = $this->makeArticle(['secundario_de' => $primary->id]);

        $result = $this->service->buildQuery()->where('id', $primary->id)->first();

        $this->assertNotNull($result, 'Primary must be found');
        $this->assertSame(1, $result->secondaries_count, 'Primary must have secondaries_count = 1');
    }
}
