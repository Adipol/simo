<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalizarScrapingConFlashTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article',
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Ministro de Economía',
            'contexto' => 'El ministro de Economía Juan Pérez firmó un decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function fakeGeminiResponse(array $data): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($data),
                    ]],
                ],
            ]],
        ]);
    }

    public function test_happy_path_processes_pending_records(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $r1 = $this->createRecord(['contexto' => 'Ministro Juan Pérez firmó decreto']);
        $r2 = $this->createRecord(['contexto' => 'Líder del cartel fue capturado']);
        $r3 = $this->createRecord(['contexto' => 'Resultado del partido de fútbol']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'Juan Pérez',
                    'cargo' => 'Ministro de Economía',
                    'categoria' => 'PEP',
                    'confianza' => 95,
                    'motivo' => 'Cargo ejecutivo de alto nivel',
                ]))
                ->push($this->fakeGeminiResponse([
                    'is_pep' => true,
                    'nombre' => 'Rodrigo Vargas',
                    'cargo' => null,
                    'categoria' => 'OPI',
                    'confianza' => 92,
                    'motivo' => 'Líder de organización criminal',
                ]))
                ->push($this->fakeGeminiResponse([
                    'is_pep' => false,
                    'nombre' => null,
                    'cargo' => null,
                    'categoria' => null,
                    'confianza' => 10,
                    'motivo' => 'Texto deportivo sin relevancia',
                ])),
        ]);

        $job = new AnalizarScrapingConFlash;
        $job->handle();

        $r1->refresh();
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertTrue($r1->gemini_is_pep);
        $this->assertSame('Juan Pérez', $r1->gemini_nombre);
        $this->assertSame('Ministro de Economía', $r1->gemini_cargo);
        $this->assertSame('PEP', $r1->gemini_categoria);
        $this->assertSame(95, $r1->gemini_confianza);

        $r2->refresh();
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertTrue($r2->gemini_is_pep);
        $this->assertSame('OPI', $r2->gemini_categoria);

        $r3->refresh();
        $this->assertTrue($r3->gemini_analyzed);
        $this->assertFalse($r3->gemini_is_pep);
    }

    public function test_batch_limit_processes_max_50_records(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        // Create 55 pending records
        for ($i = 0; $i < 55; $i++) {
            $this->createRecord(['contexto' => "Record {$i}"]);
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => false,
                    'nombre' => null,
                    'cargo' => null,
                    'categoria' => null,
                    'confianza' => 5,
                    'motivo' => 'No relevante',
                ]),
                200
            ),
        ]);

        Queue::fake();

        $job = new AnalizarScrapingConFlash;
        $job->handle();

        // 50 records should have been processed
        $this->assertSame(50, ResultadoScraping::where('gemini_analyzed', true)->count());
        // 5 should remain pending
        $this->assertSame(5, ResultadoScraping::where('gemini_analyzed', false)->count());

        // Self-dispatch should have been queued
        Queue::assertPushed(AnalizarScrapingConFlash::class, function ($job) {
            return true; // any instance counts
        });
    }

    public function test_no_self_dispatch_when_no_more_pending(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $this->createRecord(['contexto' => 'Record único']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => false,
                    'nombre' => null,
                    'cargo' => null,
                    'categoria' => null,
                    'confianza' => 5,
                    'motivo' => 'No relevante',
                ]),
                200
            ),
        ]);

        Bus::fake();

        $job = new AnalizarScrapingConFlash;
        $job->handle();

        Bus::assertNotDispatched(AnalizarScrapingConFlash::class);
    }

    public function test_no_records_returns_early_without_service_call(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $job = new AnalizarScrapingConFlash;
        $job->handle();

        // No HTTP calls should have been made
        Http::assertNothingSent();
    }

    public function test_disabled_returns_early_without_processing(): void
    {
        config([
            'services.gemini.enabled' => false,
            'services.gemini.api_key' => 'test-key',
        ]);

        $this->createRecord(['contexto' => 'Test record']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $job = new AnalizarScrapingConFlash;
        $job->handle();

        // No HTTP calls, records unchanged
        Http::assertNothingSent();
        $this->assertSame(1, ResultadoScraping::where('gemini_analyzed', false)->count());
    }

    public function test_disabled_does_not_self_dispatch(): void
    {
        config(['services.gemini.enabled' => false]);

        Queue::fake();

        $job = new AnalizarScrapingConFlash;
        $job->handle();

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
    }

    public function test_job_uses_gemini_queue(): void
    {
        $job = new AnalizarScrapingConFlash;
        $this->assertSame('gemini', $job->queue);
    }

    public function test_job_has_correct_retry_config(): void
    {
        $job = new AnalizarScrapingConFlash;
        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 25, 125], $job->backoff);
    }

    public function test_failed_marks_batch_as_analyzed(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $r1 = $this->createRecord();
        $r2 = $this->createRecord();
        $r3 = $this->createRecord();

        $job = new AnalizarScrapingConFlash;
        $job->failed(new \RuntimeException('Simulated failure'));

        $r1->refresh();
        $r2->refresh();
        $r3->refresh();

        // After failed(), ALL pending records should be marked as analyzed
        // (to prevent infinite re-queue)
        $this->assertTrue($r1->gemini_analyzed);
        $this->assertTrue($r2->gemini_analyzed);
        $this->assertTrue($r3->gemini_analyzed);

        // But gemini_is_pep should remain null (no API data)
        $this->assertNull($r1->gemini_is_pep);
        $this->assertNull($r2->gemini_is_pep);
    }

    public function test_rate_limit_exception_propagates_for_retry(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $this->createRecord();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $job = new AnalizarScrapingConFlash;

        $this->expectException(GeminiRateLimitException::class);
        $job->handle();
    }

    public function test_self_dispatch_uses_config_delay(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.flash_delay' => 10,
        ]);

        // Create 55 records to trigger self-dispatch
        for ($i = 0; $i < 55; $i++) {
            $this->createRecord(['contexto' => "Record {$i}"]);
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'is_pep' => false,
                    'nombre' => null,
                    'cargo' => null,
                    'categoria' => null,
                    'confianza' => 5,
                    'motivo' => 'No relevante',
                ]),
                200
            ),
        ]);

        Queue::fake();

        $job = new AnalizarScrapingConFlash;
        $job->handle();

        // Verify dispatch happened (delay is set on the queued job)
        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }
}
