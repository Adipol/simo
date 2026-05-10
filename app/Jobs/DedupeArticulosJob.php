<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ResultadoScraping;
use App\Services\Dedupe\DedupeArticulosService;
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
 * - Idempotent: if the article is already secondary, handle() returns immediately
 * - Feature flag: services.dedupe.enabled controls whether processing runs
 */
final class DedupeArticulosJob implements ShouldQueue, ShouldBeUnique
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

    public function handle(DedupeArticulosService $service): void
    {
        if (! config('services.dedupe.enabled', true)) {
            return;
        }

        // Early exit if the article no longer exists or is already classified
        $article = ResultadoScraping::find($this->resultadoId);
        if ($article === null || $article->secundario_de !== null) {
            return;
        }

        $service->procesar($this->resultadoId);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('gemini')->error('DedupeArticulosJob failed', [
            'resultado_id' => $this->resultadoId,
            'exception'    => $e::class,
            'message'      => $e->getMessage(),
        ]);
    }
}
