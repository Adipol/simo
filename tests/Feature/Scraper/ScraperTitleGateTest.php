<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Jobs\AnalizarScrapingConFlash;
use App\Models\GeminiUsageLog;
use App\Models\ResultadoScraping;
use App\Services\Scraper\ScraperTitleGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScraperTitleGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'fake-gemini',
            'services.deepseek_title_gate' => [
                'enabled' => true, 'api_key' => 'fake-deepseek', 'model' => 'deepseek-flash',
                'activation_after_id' => 0, 'timeout' => 20, 'batch_size' => 1,
            ],
        ]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public static function forwardCases(): array
    {
        return [
            'relevant' => ['relevant', 200, 'relevant'],
            'doubtful' => ['doubtful', 200, 'doubtful'],
            'server error' => ['relevant', 503, 'technical_error'],
            'rate limit' => ['relevant', 429, 'technical_error'],
            'invalid decision' => ['invalid', 200, 'invalid_response'],
            'invalid body' => [null, 200, 'invalid_response'],
        ];
    }

    #[DataProvider('forwardCases')]
    public function test_gate_routes_to_real_deep_service_without_lexical_terms(?string $decision, int $httpStatus, string $status): void
    {
        $record = $this->record();
        $this->fakeProviders($record, $decision, $httpStatus);

        (new AnalizarScrapingConFlash)->handle();

        $this->assertSame($status, $record->fresh()->deepseek_title_status);
        $this->assertTrue($record->fresh()->gemini_analyzed);
        $this->assertSame('Análisis profundo', $record->fresh()->gemini_motivo);
        $this->assertSame(0, $record->personas()->count());
        $this->assertSame(1, GeminiUsageLog::where('resultado_scraping_id', $record->id)->count());
        $this->assertProviderCounts(1, 1);
        Queue::assertNothingPushed();
    }

    public function test_unrelated_is_retained_with_only_title_fields_changed(): void
    {
        $record = $this->record([
            'leido' => true, 'relevante' => true, 'descartado' => true,
            'archivado_at' => now(), 'notas' => 'Manual',
        ]);
        $person = $record->personas()->create([
            'nombre' => 'Persona manual', 'categoria' => 'PEP', 'confianza' => 90, 'threshold_passed' => true,
        ]);
        $before = $record->fresh()->getRawOriginal();
        $personBefore = $person->fresh()->getRawOriginal();
        $this->fakeProviders($record, 'unrelated');

        (new AnalizarScrapingConFlash)->handle();
        config(['services.deepseek_title_gate.enabled' => false]);
        (new AnalizarScrapingConFlash)->handle();

        $after = $record->fresh();
        $this->assertSame('unrelated', $after->deepseek_title_status);
        $this->assertNotNull($after->deepseek_title_attempted_at);
        foreach ($before as $key => $value) {
            if (! str_starts_with($key, 'deepseek_title_')) {
                $this->assertSame($value, $after->getRawOriginal($key), $key);
            }
        }
        $this->assertSame($personBefore, $person->fresh()->getRawOriginal());
        $this->assertSame(0, GeminiUsageLog::count());
        $this->assertProviderCounts(1, 0);
        Queue::assertNothingPushed();
    }

    public static function legacyCases(): array
    {
        return ['disabled' => [false, 0], 'equal boundary' => [true, 100],
            'older boundary' => [true, 101], 'missing boundary' => [true, null],
            'invalid boundary' => [true, 'invalid']];
    }

    #[DataProvider('legacyCases')]
    public function test_legacy_path_does_not_classify_or_bypass_lexical_filter(bool $enabled, int|string|null $boundary): void
    {
        config(['services.deepseek_title_gate.enabled' => $enabled,
            'services.deepseek_title_gate.activation_after_id' => $boundary]);
        $record = $this->record(['id' => 100]);
        Http::fake();

        (new AnalizarScrapingConFlash)->handle();

        $this->assertNull($record->fresh()->deepseek_title_status);
        $this->assertTrue($record->fresh()->gemini_analyzed);
        $this->assertStringStartsWith('[PRE-FILTRO]', $record->fresh()->gemini_motivo);
        Http::assertNothingSent();
    }

    public function test_missing_key_at_new_boundary_fails_open_without_lexical_filter(): void
    {
        config(['services.deepseek_title_gate.api_key' => null]);
        $record = $this->record();
        $this->fakeProviders($record);

        (new AnalizarScrapingConFlash)->handle();

        $this->assertSame('technical_error', $record->fresh()->deepseek_title_status);
        $this->assertSame('not_configured', $record->fresh()->deepseek_title_error_code);
        $this->assertSame('Análisis profundo', $record->fresh()->gemini_motivo);
        $this->assertProviderCounts(0, 1);
    }

    public static function persistedCases(): array
    {
        return ['relevant' => ['relevant', false], 'doubtful' => ['doubtful', false],
            'technical error' => ['technical_error', false], 'invalid response' => ['invalid_response', false],
            'manual bypass unrelated' => ['unrelated', true], 'manual bypass unclassified' => [null, true]];
    }

    #[DataProvider('persistedCases')]
    public function test_persisted_route_or_bypass_is_reused_even_when_disabled(?string $status, bool $bypass): void
    {
        config(['services.deepseek_title_gate.enabled' => false]);
        $record = $this->record(['deepseek_title_status' => $status,
            'deepseek_bypass_at' => $bypass ? now() : null]);
        $this->fakeProviders($record);

        (new AnalizarScrapingConFlash)->handle();

        $this->assertSame($status, $record->fresh()->deepseek_title_status);
        $this->assertSame('Análisis profundo', $record->fresh()->gemini_motivo);
        $this->assertProviderCounts(0, 1);
    }

    public function test_title_is_persisted_before_gemini_failure_and_reused_on_retry(): void
    {
        $record = $this->record();
        $deepCalls = 0;
        $geminiCalls = 0;
        Http::fake(function ($request) use ($record, &$deepCalls, &$geminiCalls) {
            if (str_contains($request->url(), 'api.deepseek.com')) {
                $deepCalls++;
                return Http::response($this->titleResponse($record, 'relevant'));
            }
            $geminiCalls++;
            $this->assertSame('relevant', $record->fresh()->deepseek_title_status);
            return $geminiCalls === 1 ? Http::response([], 429) : Http::response($this->geminiResponse());
        });
        try {
            (new AnalizarScrapingConFlash)->handle();
            $this->fail('Expected retryable Gemini failure');
        } catch (GeminiRateLimitException) {
            $this->assertFalse($record->fresh()->gemini_analyzed);
        }
        (new AnalizarScrapingConFlash)->handle();
        $this->assertSame(1, $deepCalls);
        $this->assertSame(2, $geminiCalls);
        $this->assertTrue($record->fresh()->gemini_analyzed);
    }

    public static function analyzedCases(): array
    {
        return ['boolean' => [true, false], 'timestamp despite false boolean' => [false, true]];
    }

    #[DataProvider('analyzedCases')]
    public function test_already_analyzed_history_is_untouched(bool $analyzed, bool $timestamp): void
    {
        $record = $this->record(['gemini_analyzed' => $analyzed,
            'gemini_analyzed_at' => $timestamp ? now() : null]);
        $before = $record->fresh()->getRawOriginal();
        Http::fake();

        (new AnalizarScrapingConFlash)->handle();

        $this->assertSame($before, $record->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_busy_lock_skips_network_and_self_chain_has_positive_delay(): void
    {
        config(['services.gemini.flash_delay' => 0]);
        $record = $this->record();
        $lock = Cache::lock(ScraperTitleGate::lockKey($record->id), 360);
        $this->assertTrue($lock->get());
        Http::fake();
        try {
            (new AnalizarScrapingConFlash)->handle();
            Http::assertNothingSent();
            $this->assertFalse($record->fresh()->gemini_analyzed);
            Queue::assertPushed(AnalizarScrapingConFlash::class, fn ($job): bool =>
                $job->queue === 'gemini' && $job->delay->isFuture());
        } finally {
            $lock->release();
        }
    }

    public static function changedStateCases(): array
    {
        return ['held' => [['deepseek_title_status' => 'unrelated']],
            'analyzed' => [['gemini_analyzed' => true]],
            'timestamp' => [['gemini_analyzed_at' => '2026-01-01 00:00:00']]];
    }

    #[DataProvider('changedStateCases')]
    public function test_fresh_eligibility_is_rechecked_after_lock_acquisition(array $changed): void
    {
        $record = $this->record();
        $lock = \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $store = Cache::store();
        Cache::shouldReceive('driver')->andReturn($store);
        Cache::shouldReceive('lock')->once()->withArgs(fn (string $key, int $ttl): bool =>
            $key === ScraperTitleGate::lockKey($record->id) && $ttl > 300)->andReturn($lock);
        $lock->shouldReceive('get')->once()->andReturnUsing(function (callable $callback) use ($record, $changed): void {
            $record->update($changed);
            $callback();
        });
        Http::fake();

        (new AnalizarScrapingConFlash)->handle();

        Http::assertNothingSent();
        $this->assertNull($record->fresh()->deepseek_title_attempted_at);
    }

    public function test_lock_backend_error_propagates_without_unlocked_fallback(): void
    {
        $this->record();
        Http::fake();
        $store = Cache::store();
        Cache::shouldReceive('driver')->andReturn($store);
        Cache::shouldReceive('lock')->once()->andThrow(new \RuntimeException('Lock backend unavailable'));
        try {
            (new AnalizarScrapingConFlash)->handle();
            $this->fail('Expected lock failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Lock backend unavailable', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_overlapping_job_during_title_request_cannot_call_either_provider_again(): void
    {
        $record = $this->record();
        Http::fake(function ($request) use ($record) {
            if (str_contains($request->url(), 'api.deepseek.com')) {
                (new AnalizarScrapingConFlash)->handle();
                return Http::response($this->titleResponse($record, 'relevant'));
            }
            return Http::response($this->geminiResponse());
        });

        (new AnalizarScrapingConFlash)->handle();
        (new AnalizarScrapingConFlash)->handle();

        $this->assertProviderCounts(1, 1);
        $this->assertTrue($record->fresh()->gemini_analyzed);
    }

    public function test_title_transport_failure_still_reaches_deep_gemini(): void
    {
        $record = $this->record();
        Http::fake([
            'api.deepseek.com/*' => Http::failedConnection(),
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse()),
        ]);

        (new AnalizarScrapingConFlash)->handle();

        $this->assertSame('technical_error', $record->fresh()->deepseek_title_status);
        $this->assertSame('transport_error', $record->fresh()->deepseek_title_error_code);
        $this->assertSame('Análisis profundo', $record->fresh()->gemini_motivo);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    private function record(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::withoutEvents(fn (): ResultadoScraping => ResultadoScraping::factory()->create(array_merge([
            'titulo' => 'Un partido amistoso', 'contexto' => 'Un partido amistoso', 'gemini_analyzed' => false,
        ], $overrides)));
    }

    private function fakeProviders(ResultadoScraping $record, ?string $decision = 'relevant', int $status = 200): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response($decision === null ? 'invalid' : $this->titleResponse($record, $decision), $status),
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse()),
        ]);
    }

    private function titleResponse(ResultadoScraping $record, string $decision): array
    {
        return ['model' => 'deepseek-flash', 'choices' => [[
            'finish_reason' => 'stop', 'message' => ['content' => json_encode(['resultados' => [[
                'id' => $record->id, 'decision' => $decision, 'evidencia' => 'partido amistoso', 'motivo' => 'Motivo de prueba',
            ]]], JSON_THROW_ON_ERROR)],
        ]]];
    }

    private function geminiResponse(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'personas' => [], 'motivo_general' => 'Análisis profundo',
        ], JSON_THROW_ON_ERROR)]]]]], 'usageMetadata' => [
            'promptTokenCount' => 80, 'candidatesTokenCount' => 40, 'totalTokenCount' => 120,
        ]];
    }

    private function assertProviderCounts(int $deepseek, int $gemini): void
    {
        $this->assertCount($deepseek, Http::recorded(fn ($request): bool => str_contains($request->url(), 'api.deepseek.com')));
        $this->assertCount($gemini, Http::recorded(fn ($request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com')));
        Http::assertSentCount($deepseek + $gemini);
    }
}
