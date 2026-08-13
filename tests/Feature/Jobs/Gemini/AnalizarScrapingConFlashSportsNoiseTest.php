<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Gemini;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\GeminiUsageLog;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalizarScrapingConFlashSportsNoiseTest extends TestCase
{
    use RefreshDatabase;

    public function test_observe_mode_logs_only_canonical_candidates_before_forwarding_the_same_collection(): void
    {
        config(['services.dedupe.enabled' => false, 'services.gemini.enabled' => true, 'services.sports_noise.enabled' => true]);
        $candidate = $this->record(['titulo' => 'Carlos Pérez fue designado entrenador del Club Aurora.', 'contexto' => 'El Club Aurora designó a Carlos Pérez como entrenador.']);
        $primary = $this->record(['gemini_analyzed' => true]);
        $secondary = $this->record(['secundario_de' => $primary->id]);
        $logger = new \stdClass;
        $logger->events = [];
        $logger->timeline = [];
        $receiver = new \stdClass;
        $receiver->records = null;
        $receiver->timeline = &$logger->timeline;
        Queue::fake();

        Log::shouldReceive('channel')->with('gemini')->andReturn(new class($logger)
        {
            public function __construct(private \stdClass $logger) {}

            public function info(string $event, array $context = []): void
            {
                $this->logger->events[] = [$event, $context];
                $this->logger->timeline[] = 'telemetry';
            }
        });
        $this->app->instance(GeminiFiltroService::class, new class($receiver) extends GeminiFiltroService
        {
            public function __construct(private \stdClass $receiver) {}

            public function analizarLote(Collection $records): void
            {
                $this->receiver->records = $records;
                $this->receiver->timeline[] = 'forwarded';
            }
        });

        (new AnalizarScrapingConFlash)->handle();

        $this->assertInstanceOf(Collection::class, $receiver->records);
        $this->assertSame($candidate->id, $receiver->records->sole()->id);
        $this->assertSame('gemini.sports_noise_candidate', $logger->events[0][0]);
        $this->assertSame($candidate->id, $logger->events[0][1]['record_id']);
        $this->assertSame(['club_private', 'role_coach', 'status_appointed'], $logger->events[0][1]['reason_codes']);
        $this->assertSame([], $logger->events[0][1]['escape_codes']);
        $this->assertArrayHasKey('schema_version', $logger->events[0][1]);
        $this->assertArrayHasKey('rule_version', $logger->events[0][1]);
        $this->assertArrayHasKey('timestamp', $logger->events[0][1]);
        $this->assertArrayHasKey('title_fingerprint', $logger->events[0][1]);
        $this->assertArrayNotHasKey('url', $logger->events[0][1]);
        $this->assertArrayNotHasKey('contexto', $logger->events[0][1]);
        $this->assertSame(['telemetry', 'forwarded'], $logger->timeline);
        $this->assertFalse($secondary->refresh()->gemini_analyzed);
    }

    public function test_observe_mode_preserves_disabled_mode_downstream_outcomes_when_observation_logging_fails(): void
    {
        $disabled = $this->runBatch(false);
        ResultadoPersona::query()->delete();
        GeminiUsageLog::query()->delete();
        ResultadoScraping::query()->delete();
        $observed = $this->runBatch(true, true);

        $this->assertSame($disabled, $observed);
        $this->assertSame(1, $observed['requests']);
        $this->assertSame(1, $observed['usage']);
        $this->assertSame(0, $observed['personas']);
        $this->assertSame(0, $observed['queued']);
        $this->assertSame('[PRE-FILTRO] Sin mención de cargo público en el texto.', $observed['records'][0]['gemini_motivo']);
        $this->assertNull($observed['records'][1]['gemini_error_motivo']);
    }

    public function test_telemetry_logging_failure_still_forwards_the_selected_collection(): void
    {
        config(['services.dedupe.enabled' => false, 'services.gemini.enabled' => true, 'services.sports_noise.enabled' => true]);
        $record = $this->record(['titulo' => 'Carlos Pérez fue designado entrenador del Club Aurora.', 'contexto' => 'El Club Aurora designó a Carlos Pérez como entrenador.']);
        Queue::fake();
        Log::shouldReceive('channel')->with('gemini')->andReturn(new class
        {
            public function info(string $event, array $context = []): never
            {
                throw new \RuntimeException('log unavailable');
            }

            public function debug(string $event, array $context = []): void {}
        });

        (new AnalizarScrapingConFlash)->handle();

        $this->assertTrue($record->refresh()->gemini_analyzed);
        $this->assertSame('[PRE-FILTRO] Sin mención de cargo público en el texto.', $record->gemini_motivo);
    }

    /** @return array{requests: int, usage: int, personas: int, records: array<int, array<string, mixed>>, queued: int} */
    private function runBatch(bool $observe, bool $loggingFails = false): array
    {
        config([
            'services.dedupe.enabled' => false,
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
            'services.sports_noise.enabled' => $observe,
        ]);
        $this->record(['titulo' => 'Carlos Pérez fue designado entrenador del Club Aurora.', 'contexto' => 'El Club Aurora designó a Carlos Pérez como entrenador.']);
        $this->record(['titulo' => 'Ministro firma decreto', 'contexto' => 'El ministro firmó un decreto.']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->response(), 200)]);
        Queue::fake();

        if ($loggingFails) {
            $this->app->bind(\App\Services\Gemini\SportsNoiseCandidateService::class, static function (): never {
                throw new \RuntimeException('observation unavailable');
            });
        }

        (new AnalizarScrapingConFlash)->handle();

        return [
            'requests' => Http::recorded()->count(),
            'usage' => GeminiUsageLog::count(),
            'personas' => ResultadoPersona::count(),
            'records' => ResultadoScraping::orderBy('id')->get(['gemini_analyzed', 'gemini_is_pep', 'gemini_motivo', 'gemini_error_motivo'])->map->getAttributes()->all(),
            'queued' => Queue::pushed(AnalizarScrapingConFlash::class)->count(),
        ];
    }

    private function record(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.test/'.uniqid(), 'keyword' => 'test', 'pais' => 'BO', 'categoria' => 'politica',
            'titulo' => 'Ministro', 'contexto' => 'Ministro', 'relevance_score' => 80, 'gemini_analyzed' => false,
        ], $overrides));
    }

    private function response(): string
    {
        return json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode(['personas' => [], 'motivo_general' => 'No PEP.'])]]],
            ]],
        ]);
    }
}
