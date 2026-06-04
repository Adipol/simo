<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Cambio;
use App\Services\Gemini\GeminiAnalisisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalizarCambioConPro implements ShouldQueue
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
     * Per-record worst case: a SINGLE HTTP call — either multimodal (≤60s) OR text-only (≤45s).
     * The degrade-to-text branch in procesarCambioMultimodal() runs procesarCambio() and returns
     * immediately (no images path), mutually exclusive with the multimodal HTTP call. A failed
     * multimodal call is caught and marked as failed with no text retry.
     * 3-record aggregate worst case: 3 × 60s = 180s, comfortably within this 300s timeout.
     *
     * This timeout is a non-destructive safety net. On SIGKILL the job retries via the exponential
     * backoff schedule; failed() is log-only so no records are stranded (they stay
     * gemini_analyzed=false and are reprocessed on the next scheduler dispatch).
     * Completed records have gemini_analyzed=true and are excluded by pendingQuery(), so
     * re-dispatching never reprocesses already-analyzed rows. The gemini_analyzed_at timestamp
     * is the service-level idempotency guard in analizarLote — both columns are set together
     * on success.
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

        // Batch cap: 3 records. Per-record worst case is a single HTTP call (≤60s multimodal
        // or ≤45s text-only); 3-record aggregate worst case ≈ 180s, within the 300s job timeout.
        $records = $this->pendingQuery()->limit(3)->get();

        if ($records->isEmpty()) {
            return;
        }

        app(GeminiAnalisisService::class)->analizarLote($records);

        if ($this->hayMasPendientes()) {
            self::dispatch()
                ->delay(now()->addSeconds(config('services.gemini.pro_delay', 30)))
                ->onQueue('gemini');
        }
    }

    public function failed(\Throwable $e): void
    {
        // Log-only: do NOT mutate records. Records stay gemini_analyzed=false so the
        // next scheduler dispatch reprocesses them. Mutating here causes stranding
        // (analyzed=true without analyzed_at), which the Pro recovery command exists to fix.
        Log::channel('gemini')->error('Job AnalizarCambioConPro failed', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
        ]);
    }

    private function pendingQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Cambio::where('gemini_analyzed', false)
            ->orderBy('fecha', 'desc');
    }

    private function hayMasPendientes(): bool
    {
        return Cambio::where('gemini_analyzed', false)->exists();
    }
}
