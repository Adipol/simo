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

/**
 * P4.T10 — RED tests for #[Computed] pendingCount() in Resultados component.
 *
 * Design D9: pendingCount counts articles where:
 *   - gemini_analyzed = false
 *   - descartado = false
 *   - archivado_at IS NULL
 *   - secundario_de IS NULL (only count primaries)
 */
class ResultadosPendingCountTest extends TestCase
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
            'gemini_analyzed'  => false,
        ], $overrides));
    }

    // ─── pendingCount returns correct count ───────────────────────────────────

    public function test_pending_count_returns_count_of_unanalyzed_non_discarded(): void
    {
        $this->makeArticle(['gemini_analyzed' => false]); // pending
        $this->makeArticle(['gemini_analyzed' => false]); // pending
        $this->makeArticle(['gemini_analyzed' => true]);  // analyzed → NOT counted

        $component = Livewire::test(Resultados::class);

        $this->assertSame(2, $component->instance()->pendingCount);
    }

    public function test_pending_count_returns_0_when_no_pending_articles(): void
    {
        $this->makeArticle(['gemini_analyzed' => true]);
        $this->makeArticle(['gemini_analyzed' => true]);

        $component = Livewire::test(Resultados::class);

        $this->assertSame(0, $component->instance()->pendingCount);
    }

    // ─── pendingCount excludes descartado ─────────────────────────────────────

    public function test_pending_count_excludes_discartado(): void
    {
        $this->makeArticle(['gemini_analyzed' => false, 'descartado' => false]); // counted
        $this->makeArticle(['gemini_analyzed' => false, 'descartado' => true]);  // NOT counted

        $component = Livewire::test(Resultados::class);

        $this->assertSame(1, $component->instance()->pendingCount);
    }

    // ─── pendingCount excludes archived ───────────────────────────────────────

    public function test_pending_count_excludes_archived(): void
    {
        $this->makeArticle(['gemini_analyzed' => false, 'archivado_at' => null]);    // counted
        $this->makeArticle(['gemini_analyzed' => false, 'archivado_at' => now()]);   // NOT counted

        $component = Livewire::test(Resultados::class);

        $this->assertSame(1, $component->instance()->pendingCount);
    }

    // ─── Badge visibility based on pendingCount ────────────────────────────────

    public function test_pending_badge_visible_in_view_when_count_gt_0(): void
    {
        $this->makeArticle(['gemini_analyzed' => false]);

        Livewire::test(Resultados::class)
            ->assertSee('procesando');
    }

    public function test_pending_badge_absent_when_count_is_0(): void
    {
        // Only analyzed articles → pendingCount = 0
        $this->makeArticle(['gemini_analyzed' => true]);

        Livewire::test(Resultados::class)
            ->assertDontSee('procesando');
    }
}
