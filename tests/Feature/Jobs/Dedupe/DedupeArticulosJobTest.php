<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Dedupe;

use App\Jobs\AnalizarScrapingConFlash;
use App\Jobs\DedupeArticulosJob;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * P4.T5 — RED tests for DedupeArticulosJob and ResultadoScrapingObserver dispatch.
 *
 * Tests:
 *  - Observer dispatches DedupeArticulosJob when ResultadoScraping is created
 *  - Job is dispatched to the 'dedupe' queue
 *  - Job has 3 tries and exponential backoff
 *  - ShouldBeUnique: uniqueId() returns a stable string per article
 *  - Job skips article already marked as secondary (idempotent)
 *  - Kill switch: services.dedupe.enabled=false → no job dispatched
 */
class DedupeArticulosJobTest extends TestCase
{
    use RefreshDatabase;

    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Gemini so the Gemini observer doesn't interfere
        config(['services.gemini.enabled' => false]);

        $this->sitio = SitioWeb::create([
            'url' => 'https://example.com',
            'nombre' => 'Example',
            'pais' => 'BO',
            'activo' => true,
        ]);
    }

    private function makeArticle(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article-'.uniqid(),
            'keyword' => 'test',
            'sitio_id' => $this->sitio->id,
            'pais' => 'BO',
            'titulo' => 'Test article title',
            'contexto' => '',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'descartado' => false,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    // ─── Observer dispatch ────────────────────────────────────────────────────

    public function test_creating_resultado_scraping_dispatches_dedupe_job(): void
    {
        Bus::fake();

        config(['services.dedupe.enabled' => true]);

        $article = $this->makeArticle();

        Bus::assertDispatched(DedupeArticulosJob::class, function (DedupeArticulosJob $job) use ($article): bool {
            return $job->resultadoId === $article->id;
        });
    }

    public function test_dedupe_job_is_dispatched_to_dedupe_queue(): void
    {
        Queue::fake();

        config(['services.dedupe.enabled' => true]);

        $this->makeArticle();

        Queue::assertPushedOn('dedupe', DedupeArticulosJob::class);
    }

    // ─── Kill switch ──────────────────────────────────────────────────────────

    public function test_no_dedupe_job_dispatched_when_kill_switch_off(): void
    {
        Bus::fake();

        config(['services.dedupe.enabled' => false]);

        $this->makeArticle();

        Bus::assertNotDispatched(DedupeArticulosJob::class);
    }

    // ─── Job configuration ────────────────────────────────────────────────────

    public function test_job_has_3_tries(): void
    {
        $job = new DedupeArticulosJob(1);
        $this->assertSame(3, $job->tries);
    }

    public function test_job_has_exponential_backoff(): void
    {
        $job = new DedupeArticulosJob(1);
        $this->assertIsArray($job->backoff);
        $this->assertCount(3, $job->backoff, 'Backoff must have 3 entries for 3 retries');
        // Each entry must be larger than the previous (exponential)
        $this->assertGreaterThan($job->backoff[0], $job->backoff[1]);
        $this->assertGreaterThan($job->backoff[1], $job->backoff[2]);
    }

    public function test_job_unique_id_is_stable_per_article(): void
    {
        $job1 = new DedupeArticulosJob(42);
        $job2 = new DedupeArticulosJob(42);
        $job3 = new DedupeArticulosJob(99);

        $this->assertSame($job1->uniqueId(), $job2->uniqueId(), 'Same article → same uniqueId');
        $this->assertNotSame($job1->uniqueId(), $job3->uniqueId(), 'Different articles → different uniqueId');
    }

    // ─── Job handle — skips already-secondary ─────────────────────────────────

    public function test_job_handle_skips_article_already_secondary(): void
    {
        config(['services.dedupe.enabled' => true]);

        $primary = $this->makeArticle();
        $secondary = $this->makeArticle(['secundario_de' => $primary->id]);

        $job = new DedupeArticulosJob($secondary->id);
        app()->call([$job, 'handle']);

        $secondary->refresh();
        $this->assertSame($primary->id, $secondary->secundario_de, 'Already-secondary must stay secondary of same primary');
        $this->assertNotNull($secondary->dedupe_processed_at, 'Already-secondary rows must leave the safety-net queue');
    }

    // ─── Job handle — calls service and processes article ────────────────────

    public function test_job_handle_processes_primary_article(): void
    {
        config(['services.dedupe.enabled' => true]);

        $article = $this->makeArticle();

        $job = new DedupeArticulosJob($article->id);
        app()->call([$job, 'handle']);

        // On SQLite there's no pg_trgm so no candidates are found.
        // The article should remain a primary (secundario_de = null).
        $article->refresh();
        $this->assertNull($article->secundario_de, 'With no candidates, article must remain primary after job');
        $this->assertNotNull($article->dedupe_processed_at);
    }

    public function test_primary_dispatches_gemini_only_after_successful_dedupe(): void
    {
        config([
            'services.dedupe.enabled' => true,
            'services.gemini.enabled' => true,
        ]);
        Queue::fake();
        $article = $this->makeArticle();
        Queue::fake();

        $job = new DedupeArticulosJob($article->id);
        app()->call([$job, 'handle']);

        $this->assertNotNull($article->refresh()->dedupe_processed_at);
        Queue::assertPushedOn('gemini', AnalizarScrapingConFlash::class);
    }

    public function test_secondary_skips_gemini_and_logs_avoided_analysis(): void
    {
        config([
            'services.dedupe.enabled' => true,
            'services.gemini.enabled' => true,
        ]);
        Queue::fake();
        $primary = $this->makeArticle(['dedupe_processed_at' => now()]);
        $secondary = $this->makeArticle(['secundario_de' => $primary->id]);
        Queue::fake();

        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('dedupe.gemini_skipped_secondary', \Mockery::on(
                fn (array $context): bool => $context['article_id'] === $secondary->id
                    && $context['primary_id'] === $primary->id
                    && $context['reason'] === 'secondary_article'
            ));
        Log::shouldReceive('channel')->once()->with('gemini')->andReturn($logger);

        $job = new DedupeArticulosJob($secondary->id);
        app()->call([$job, 'handle']);

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
        $this->assertFalse($secondary->refresh()->gemini_analyzed);
        $this->assertNotNull($secondary->dedupe_processed_at);
    }

    public function test_already_analyzed_primary_does_not_dispatch_gemini(): void
    {
        config([
            'services.dedupe.enabled' => true,
            'services.gemini.enabled' => true,
        ]);
        Queue::fake();
        $article = $this->makeArticle(['gemini_analyzed' => true]);
        Queue::fake();

        $job = new DedupeArticulosJob($article->id);
        app()->call([$job, 'handle']);

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
    }

    // ─── Job handle — respects kill switch ───────────────────────────────────

    public function test_job_handle_skips_when_dedupe_disabled(): void
    {
        config(['services.dedupe.enabled' => false]);

        // Create with its own unique URL to avoid constraint issues
        $article = ResultadoScraping::create([
            'url' => 'https://example.com/dedupe-disabled-'.uniqid(),
            'keyword' => 'test',
            'sitio_id' => $this->sitio->id,
            'pais' => 'BO',
            'titulo' => 'Article that should not be processed',
            'contexto' => '',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'descartado' => false,
            'gemini_analyzed' => false,
        ]);

        $job = new DedupeArticulosJob($article->id);
        app()->call([$job, 'handle']);

        // When kill switch is off at the job level, nothing changes
        // (the service itself also checks habilitado in ConfigScript, but
        // the job should return early before even calling the service)
        $article->refresh();
        $this->assertNull($article->secundario_de, 'Kill switch must prevent any processing');
    }
}
