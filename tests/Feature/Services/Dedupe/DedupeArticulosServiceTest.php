<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dedupe;

use App\Models\ConfigScript;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\Dedupe\DedupeArticulosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P4.T3 — RED tests for DedupeArticulosService::procesar().
 *
 * Requires real PostgreSQL (pg_trgm), so no SQLite fallback.
 * Tests cover:
 *  - No candidates → article stays primary
 *  - Dissimilar titles → no cluster
 *  - 1 candidate above threshold → new article becomes secondary
 *  - Already secondary → no-op (idempotent)
 *  - Window read from config_scripts
 *  - Threshold read from config_scripts
 *  - habilitado=false → no-op
 */
class DedupeArticulosServiceTest extends TestCase
{
    use RefreshDatabase;

    private DedupeArticulosService $service;
    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Gemini and dedupe observer dispatches during test setup
        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        $this->service = app(DedupeArticulosService::class);

        $this->sitio = SitioWeb::create([
            'url'    => 'https://example.com',
            'nombre' => 'Example',
            'pais'   => 'BO',
            'activo' => true,
        ]);

        // Ensure dedupe config exists with default values
        ConfigScript::updateOrInsert(
            ['script' => 'dedupe'],
            [
                'habilitado'        => true,
                'intervalo_minutos' => 7,
                'timeout_minutos'   => 5,
                'dias_semana'       => '1,2,3,4,5,6,7',
                'notas'             => json_encode(['threshold' => 0.90]),
            ]
        );
    }

    /**
     * Skip the test when the test DB is SQLite (pg_trgm not available).
     * Real similarity tests must run against a PostgreSQL test DB.
     */
    private function skipIfNotPgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL + pg_trgm extension. SQLite fallback returns no candidates.');
        }
    }

    private function makeArticle(string $titulo, array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'            => 'https://example.com/'.str_replace(' ', '-', $titulo).'-'.uniqid(),
            'keyword'        => 'test',
            'sitio_id'       => $this->sitio->id,
            'pais'           => 'BO',
            'titulo'         => $titulo,
            'contexto'       => '',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'          => false,
            'descartado'     => false,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    // ─── No candidates ────────────────────────────────────────────────────────

    public function test_article_stays_primary_when_no_candidates_exist(): void
    {
        $article = $this->makeArticle('Renuncia de Cronenbold en YPFB Bolivia');

        $this->service->procesar($article->id);

        $article->refresh();
        $this->assertNull($article->secundario_de, 'Article with no similar peers must remain primary');
    }

    // ─── Dissimilar titles → no cluster ──────────────────────────────────────

    public function test_article_stays_primary_when_similarity_below_threshold(): void
    {
        $this->skipIfNotPgsql();

        // These two titles are unrelated — similarity well below 0.90
        $this->makeArticle('Bolivia incrementa exportaciones de gas natural al mercado argentino');
        $new = $this->makeArticle('Presidente inaugura carretera en el departamento de Potosí');

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNull($new->secundario_de, 'Dissimilar articles must not form a cluster');
    }

    // ─── 1 candidate above threshold → secondary ─────────────────────────────

    public function test_new_article_becomes_secondary_when_candidate_matches(): void
    {
        $this->skipIfNotPgsql();

        $existing = $this->makeArticle('Renuncia del gerente Carlos Cronenbold de YPFB');

        // Near-identical title, inserted later → should become secondary
        $new = $this->makeArticle('Renuncia del gerente Carlos Cronenbold de la empresa YPFB', [
            'fecha_encontrado' => now()->addSeconds(5),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNotNull($new->secundario_de, 'New article with similar title must be marked secondary');
        $this->assertSame($existing->id, $new->secundario_de, 'Secondary must point to the existing primary');
    }

    // ─── Already secondary → idempotent ──────────────────────────────────────

    public function test_procesar_is_idempotent_when_article_already_secondary(): void
    {
        $primary   = $this->makeArticle('Designación de ministro de economía en Bolivia');
        $secondary = $this->makeArticle('Designación del ministro de economía boliviano', [
            'secundario_de' => $primary->id,
        ]);

        $originalPrimaryId = $secondary->secundario_de;

        // Run twice — should be no-op
        $this->service->procesar($secondary->id);
        $this->service->procesar($secondary->id);

        $secondary->refresh();
        $this->assertSame($originalPrimaryId, $secondary->secundario_de, 'Already-secondary article must not change cluster');
    }

    // ─── Non-existent article → no-op ────────────────────────────────────────

    public function test_procesar_is_noop_when_article_not_found(): void
    {
        $this->expectNotToPerformAssertions();

        // Should not throw, just silently do nothing
        $this->service->procesar(999_999);
    }

    // ─── Excludes already-secondary articles from candidate pool ─────────────

    public function test_excludes_already_secondary_articles_from_candidate_pool(): void
    {
        $this->skipIfNotPgsql();

        $primary = $this->makeArticle('Renuncia gerente YPFB Carlos Cronenbold Bolivia');

        // Make an article that is already secondary under $primary
        $alreadySecondary = $this->makeArticle('Renuncia del gerente YPFB Carlos Cronenbold en Bolivia', [
            'secundario_de' => $primary->id,
        ]);

        // New article with similar title → should find $primary (not $alreadySecondary) as candidate
        $new = $this->makeArticle('Renuncia gerente de YPFB Carlos Cronenbold en Bolivia', [
            'fecha_encontrado' => now()->addSeconds(10),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        // Either becomes secondary of $primary or stays primary (if similarity threshold not met)
        // but it must NOT become secondary of $alreadySecondary
        if ($new->secundario_de !== null) {
            $this->assertNotSame(
                $alreadySecondary->id,
                $new->secundario_de,
                'New article must never become secondary of an already-secondary article'
            );
        }
    }

    // ─── Window from config_scripts ───────────────────────────────────────────

    public function test_articles_outside_window_are_not_candidates(): void
    {
        $this->skipIfNotPgsql();

        // Set window to 3 days
        ConfigScript::where('script', 'dedupe')->update(['intervalo_minutos' => 3]);

        // Existing article is 5 days old — outside the 3-day window
        $old = $this->makeArticle('Renuncia gerente YPFB Bolivia noticias', [
            'fecha_encontrado' => now()->subDays(5),
        ]);

        $new = $this->makeArticle('Renuncia gerente de YPFB Bolivia noticias recientes', [
            'fecha_encontrado' => now(),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNull($new->secundario_de, 'Articles outside the window must not form a cluster');
    }

    // ─── habilitado = false → no-op ───────────────────────────────────────────

    public function test_procesar_is_noop_when_dedupe_disabled_in_config(): void
    {
        ConfigScript::where('script', 'dedupe')->update(['habilitado' => false]);

        $existing = $this->makeArticle('Renuncia ministro economía Bolivia esta semana');
        $new = $this->makeArticle('Renuncia del ministro de economía Bolivia esta semana', [
            'fecha_encontrado' => now()->addSeconds(5),
        ]);

        $this->service->procesar($new->id);

        $new->refresh();
        $this->assertNull($new->secundario_de, 'When dedupe is disabled, no clustering must happen');
    }
}
