<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuthorityReviewAnalysisOutbox;
use App\Services\Gemini\GeminiAnalisisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class ProcessAuthorityReviewAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 25, 125];

    public int $timeout = 300;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly string $idempotencyKey,
    ) {
        $this->onQueue('gemini');
    }

    public function handle(GeminiAnalisisService $analysis): void
    {
        if (! config('services.gemini.enabled')) {
            return;
        }

        $token = (string) Str::uuid();
        $claimed = AuthorityReviewAnalysisOutbox::query()
            ->whereKey($this->outboxEventId)
            ->where('idempotency_key', $this->idempotencyKey)
            ->whereNull('processed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('processing_claimed_at')
                    ->orWhere('processing_claimed_at', '<=', now()->subMinutes(10));
            })
            ->update([
                'processing_claim_token' => $token,
                'processing_claimed_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $event = AuthorityReviewAnalysisOutbox::query()->with('cambio.fuente')->findOrFail($this->outboxEventId);

        try {
            $analysis->analizarLote(collect([$event->cambio]));
        } catch (Throwable $exception) {
            AuthorityReviewAnalysisOutbox::query()
                ->whereKey($event->id)
                ->where('processing_claim_token', $token)
                ->update([
                    'processing_claim_token' => null,
                    'processing_claimed_at' => null,
                ]);

            throw $exception;
        }

        if ($event->cambio->fresh()->gemini_analyzed_at !== null) {
            AuthorityReviewAnalysisOutbox::query()
                ->whereKey($event->id)
                ->where('processing_claim_token', $token)
                ->update([
                    'processed_at' => now(),
                    'processing_claim_token' => null,
                    'processing_claimed_at' => null,
                ]);
        }
    }
}
