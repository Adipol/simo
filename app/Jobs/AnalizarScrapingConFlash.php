<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use App\Services\Gemini\GeminiFlashEligibilityService;
use App\Services\Gemini\SportsNoiseCandidateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalizarScrapingConFlash implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 25, 125];

    /**
     * Job-level PCNTL timeout (seconds). Per Laravel Worker::timeoutForJob this
     * property WINS over the worker --timeout flag.
     *
     * Timeout pyramid invariant:
     *   max HTTP call (60s) < job $timeout (300s) < retry_after (360s, set via DB_QUEUE_RETRY_AFTER in infra)
     *
     * Infra note: DB_QUEUE_RETRY_AFTER=360 MUST be set in the VPS .env — without it the
     * DB queue driver re-releases the running job at 90s causing duplicate dispatch.
     */
    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('gemini');
    }

    public function handle(): void
    {
        if (! config('services.gemini.enabled')) {
            return;
        }

        // Batch cap: 10×~5-10s HTTP calls ≈ 50-100s, well within the 300s job timeout.
        $records = $this->pendingQuery()->limit(10)->get();

        if ($records->isEmpty()) {
            return;
        }

        $this->observeSportsNoiseCandidates($records);

        app(GeminiFiltroService::class)->analizarLote($records);

        if ($this->hayMasPendientes()) {
            self::dispatch()
                ->delay(now()->addSeconds(config('services.gemini.flash_delay', 4)))
                ->onQueue('gemini');
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('gemini')->error('AnalizarScrapingConFlash batch failed', [
            'exception' => $exception,
        ]);
    }

    /** @return Builder<ResultadoScraping> */
    private function pendingQuery(): Builder
    {
        return app(GeminiFlashEligibilityService::class)->query()
            ->orderBy('fecha_encontrado', 'desc');
    }

    private function hayMasPendientes(): bool
    {
        return app(GeminiFlashEligibilityService::class)->query()->exists();
    }

    /** @param \Illuminate\Support\Collection<int, ResultadoScraping> $records */
    private function observeSportsNoiseCandidates(\Illuminate\Support\Collection $records): void
    {
        try {
            $candidateService = app(SportsNoiseCandidateService::class);

            foreach ($records as $record) {
                $decision = $candidateService->decide($record);

                if ($decision->outcome !== 'candidate') {
                    continue;
                }

                Log::channel('gemini')->info('gemini.sports_noise_candidate', [
                    'schema_version' => 'v1',
                    'rule_version' => $decision->ruleVersion,
                    'record_id' => $record->id,
                    'reason_codes' => $decision->reasonCodes,
                    'escape_codes' => $decision->escapeCodes,
                    'timestamp' => now()->toIso8601String(),
                    'title_fingerprint' => $decision->titleFingerprint,
                    'excerpt' => $decision->excerpt,
                ]);
            }
        } catch (\Throwable) {
            // Observation must never change the established downstream batch behavior.
        }
    }
}
