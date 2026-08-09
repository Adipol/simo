<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ResultadoScraping;
use App\Services\Dedupe\DedupeArticulosService;
use App\Services\Dedupe\DedupeConfigurationService;
use App\Services\Gemini\GeminiFlashEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job that processes one ResultadoScraping article through the deduplication pipeline.
 *
 * Design D6:
 * - Queue: 'dedupe' (separate from Gemini jobs for independent throttling)
 * - Tries: 3 with exponential backoff [5, 25, 125] seconds
 * - ShouldBeUnique: prevents duplicate processing of the same article (5-min lock)
 * - Idempotent: already-classified rows are stamped but never sent to Gemini
 * - Kill switches: environment and database configuration both bypass dedupe safely
 */
final class DedupeArticulosJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 25, 125];

    /** Unique lock duration in seconds (5 minutes). */
    public int $uniqueFor = 300;

    public function __construct(public int $resultadoId)
    {
        $this->onQueue('dedupe');
    }

    /**
     * The unique identifier for this job.
     * Ensures that only one job per article can be in-flight at a time.
     */
    public function uniqueId(): string
    {
        return "dedupe-{$this->resultadoId}";
    }

    public function handle(
        DedupeArticulosService $service,
        DedupeConfigurationService $dedupeConfiguration,
        GeminiFlashEligibilityService $geminiEligibility,
    ): void {
        if (! $dedupeConfiguration->isEnabled()) {
            $this->dispatchGeminiWhenPending($geminiEligibility);

            return;
        }

        $article = ResultadoScraping::find($this->resultadoId);
        if ($article === null) {
            return;
        }

        $service->procesar($this->resultadoId);

        $article->refresh();

        if ($article->secundario_de !== null) {
            Log::channel('gemini')->info('dedupe.gemini_skipped_secondary', [
                'article_id' => $article->id,
                'primary_id' => $article->secundario_de,
                'gemini_analyzed' => $article->gemini_analyzed,
                'reason' => 'secondary_article',
            ]);

            return;
        }

        $this->dispatchGeminiWhenPending($geminiEligibility);
    }

    private function dispatchGeminiWhenPending(GeminiFlashEligibilityService $geminiEligibility): void
    {
        if (! config('services.gemini.enabled')) {
            return;
        }

        $isPending = $geminiEligibility->query()
            ->whereKey($this->resultadoId)
            ->exists();

        if (! $isPending) {
            return;
        }

        AnalizarScrapingConFlash::dispatch()
            ->delay(now()->addSeconds(config('services.gemini.flash_delay', 4)));
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('gemini')->error('DedupeArticulosJob failed', [
            'resultado_id' => $this->resultadoId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
