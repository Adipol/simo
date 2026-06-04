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
     * Timeout pyramid invariant:
     *   max HTTP call (60s multimodal) < job $timeout (300s) < retry_after (360s, infra)
     *
     * Batch math: 4 mixed calls × ceiling 60s = 240s worst-case, 60s headroom.
     * All-multimodal realistic: 4 × 30-50s ≈ 120-200s. Reduce batch to 3 if P99 > 50s.
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

        // Batch cap: 4 calls × 60s max = 240s worst-case, within the 300s job timeout.
        $records = $this->pendingQuery()->limit(4)->get();

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
