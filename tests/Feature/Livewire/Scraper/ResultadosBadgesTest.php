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

/**
 * P4.T12 — RED tests for badges in Resultados view.
 *
 * Tests:
 *  - "X procesando" header badge appears when pendingCount > 0
 *  - "X procesando" header badge absent when pendingCount = 0
 *  - "+N medios" row badge appears when article has secondaries
 *  - "+N medios" row badge absent when article has 0 secondaries
 *  - Secondary articles do NOT appear in default list
 */
class ResultadosBadgesTest extends TestCase
{
    use RefreshDatabase;

    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

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
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'            => false,
            'descartado'       => false,
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => false,
        ], $overrides));
    }

    // ─── "X procesando" header badge ─────────────────────────────────────────

    public function test_pending_badge_appears_when_pending_count_gt_0(): void
    {
        // Create 1 pending article + 1 analyzed article (so the list isn't empty)
        $this->makeArticle(['gemini_analyzed' => false, 'keyword' => 'pend-art']);
        $this->makeArticle(['gemini_analyzed' => true,  'keyword' => 'done-art']);

        Livewire::test(Resultados::class)
            ->assertSee('procesando');
    }

    public function test_pending_badge_absent_when_zero_pending(): void
    {
        $this->makeArticle(['gemini_analyzed' => true]);

        Livewire::test(Resultados::class)
            ->assertDontSee('procesando');
    }

    // ─── "+N medios" row badge ────────────────────────────────────────────────

    public function test_plus_n_badge_shows_for_primary_with_secondaries(): void
    {
        $primary   = $this->makeArticle(['keyword' => 'primary-kw-xyz']);
        $secondary = $this->makeArticle([
            'keyword'       => 'secondary-kw-xyz',
            'secundario_de' => $primary->id,
        ]);

        Livewire::test(Resultados::class)
            ->assertSee('medios');
    }

    public function test_plus_n_badge_absent_for_article_with_zero_secondaries(): void
    {
        // Only one article with no secondaries
        $this->makeArticle(['keyword' => 'solo-kw-xyz']);

        Livewire::test(Resultados::class)
            ->assertDontSee('medios');
    }

    // ─── Secondary articles hidden in default list ───────────────────────────

    public function test_secondary_article_not_shown_in_default_list(): void
    {
        $primary   = $this->makeArticle(['keyword' => 'primary-main-kw']);
        $secondary = $this->makeArticle([
            'keyword'       => 'secondary-hidden-kw',
            'secundario_de' => $primary->id,
        ]);

        Livewire::test(Resultados::class)
            ->assertSee('primary-main-kw')
            ->assertDontSee('secondary-hidden-kw');
    }
}
