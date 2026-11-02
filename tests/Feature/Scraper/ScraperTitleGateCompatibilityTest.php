<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\GeminiUsageLog;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScraperTitleGateCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_gate_keeps_ten_deep_calls_usage_order_and_exact_chain_delay(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'UTC'));
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'fixture-only',
            'services.gemini.flash_delay' => 17,
            'services.deepseek_title_gate.enabled' => false,
            'services.dedupe.enabled' => false,
        ]);
        Http::preventStrayRequests();
        $ids = [];
        for ($i = 1; $i <= 11; $i++) {
            $record = ResultadoScraping::withoutEvents(fn (): ResultadoScraping => ResultadoScraping::create([
                'url' => 'https://fixture.invalid/'.$i, 'keyword' => 'ministro', 'pais' => 'BO',
                'categoria' => 'politica', 'titulo' => 'Ministro caso '.$i,
                'contexto' => 'El ministro de Economía firmó decreto caso '.$i,
                'fecha_encontrado' => now()->addSeconds($i), 'relevance_score' => 80,
                'gemini_analyzed' => false,
            ]));
            $ids[] = $record->id;
        }
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'personas' => [], 'motivo_general' => 'Sin PEP',
            ], JSON_THROW_ON_ERROR)]]]]],
            'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 40, 'totalTokenCount' => 120],
        ])]);
        Queue::fake();

        (new AnalizarScrapingConFlash())->handle();

        Http::assertSentCount(10);
        $expected = array_slice(array_reverse($ids), 0, 10);
        $usage = GeminiUsageLog::orderBy('id')->get();
        $this->assertCount(10, $usage);
        $this->assertSame($expected, $usage->pluck('resultado_scraping_id')->all());
        foreach ($usage as $entry) {
            $this->assertSame(80, $entry->prompt_tokens);
            $this->assertSame(40, $entry->completion_tokens);
            $this->assertSame(120, $entry->total_tokens);
            $this->assertSame('filtro', $entry->request_type);
        }
        $this->assertSame([$ids[0]], ResultadoScraping::where('gemini_analyzed', false)->pluck('id')->all());
        Queue::assertPushed(AnalizarScrapingConFlash::class, 1);
        Queue::assertPushed(AnalizarScrapingConFlash::class, fn (AnalizarScrapingConFlash $job): bool =>
            $job->queue === 'gemini' && $job->delay instanceof Carbon
            && $job->delay->equalTo(now()->addSeconds(17)));
        $this->travelBack();
    }
}
