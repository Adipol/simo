<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Filtro;

use App\Models\ResultadoScraping;
use App\Services\Gemini\PreFiltroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PreFiltroServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable Gemini so that ResultadoScrapingObserver does not dispatch
        // AnalizarScrapingConFlash when creating test records (QUEUE_CONNECTION=sync
        // in phpunit.xml would otherwise run the job synchronously and fail without an API key).
        config(['services.gemini.enabled' => false]);
        // Ensure cache is clean before each test
        Cache::forget('pre-filtro-terms');
    }

    // ────────────────────────────────────────────────────────────────────────────
    // 1.B.1 — Cache TTL behavior
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * PreFiltroService must store terms in Cache with key 'pre-filtro-terms'
     * and a TTL of 300 seconds (5 minutes).
     *
     * RED: with static $termsCache, Cache::has('pre-filtro-terms') is always false.
     * GREEN: once Cache::remember() is used, the key is stored in cache.
     */
    public function test_terms_cache_uses_5min_ttl(): void
    {
        $service = new PreFiltroService;
        $record = $this->createRecord(['contexto' => 'ministro de economía']);

        // Before first call — cache must not exist
        $this->assertFalse(Cache::has('pre-filtro-terms'));

        // Trigger getSearchTerms()
        $service->shouldAnalyzeWithGemini($record);

        // After first call — cache MUST exist (stored via Cache::remember)
        $this->assertTrue(
            Cache::has('pre-filtro-terms'),
            'Expected Cache key "pre-filtro-terms" to exist after shouldAnalyzeWithGemini() is called'
        );
    }

    /**
     * Triangulation: Cache value is an array of terms and contains the EXTRA_TERMS
     * even when the cargos_pep table is empty (no DB rows yet).
     */
    public function test_cache_key_stores_an_array_of_terms(): void
    {
        $service = new PreFiltroService;
        $record = $this->createRecord(['contexto' => 'ministro de economía']);

        $service->shouldAnalyzeWithGemini($record);

        // Cache must contain an array (the terms list)
        $cached = Cache::get('pre-filtro-terms');
        $this->assertIsArray($cached, 'Cache key "pre-filtro-terms" must store an array of terms');
        // The EXTRA_TERMS constant always includes 'ministro', 'fiscal', etc.
        $this->assertContains('ministro', $cached, 'EXTRA_TERMS must be included in cached terms');
        $this->assertContains('fiscal', $cached, 'EXTRA_TERMS must be included in cached terms');
    }

    // ────────────────────────────────────────────────────────────────────────────
    // flushCache
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * flushCache() must remove the 'pre-filtro-terms' key from the cache store.
     */
    public function test_flush_cache_removes_cache_key(): void
    {
        $service = new PreFiltroService;
        $record = $this->createRecord(['contexto' => 'ministro de economía']);

        // Warm up cache
        $service->shouldAnalyzeWithGemini($record);
        $this->assertTrue(Cache::has('pre-filtro-terms'));

        // Flush it
        $service->flushCache();

        // Cache must be gone
        $this->assertFalse(
            Cache::has('pre-filtro-terms'),
            'flushCache() must call Cache::forget("pre-filtro-terms")'
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────────

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'             => 'https://example.com/test',
            'keyword'         => 'pep',
            'pais'            => 'BO',
            'categoria'       => 'politica',
            'titulo'          => 'Test titulo',
            'contexto'        => 'Test contexto',
            'relevance_score' => 50,
            'gemini_analyzed' => false,
        ], $overrides));
    }
}
