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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessAuthorityReviewAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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
            ->whereNull('terminal_at')
            ->where('processing_attempts', '<', AuthorityReviewAnalysisOutbox::MAX_PROCESSING_ATTEMPTS)
            ->where(fn (Builder $query): Builder => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(function (Builder $query): void {
                $query->whereNull('processing_claimed_at')
                    ->orWhere('processing_claimed_at', '<=', now()->subMinutes(10));
            })
            ->update([
                'processing_claim_token' => $token,
                'processing_claimed_at' => now(),
                'processing_attempts' => DB::raw('processing_attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $event = AuthorityReviewAnalysisOutbox::query()->with('cambio.fuente')->findOrFail($this->outboxEventId);

        try {
            $analysis->analizarLote(collect([$event->cambio]));
        } catch (Throwable $exception) {
            $attempts = $event->fresh()->processing_attempts;
            $terminal = $attempts >= AuthorityReviewAnalysisOutbox::MAX_PROCESSING_ATTEMPTS;
            AuthorityReviewAnalysisOutbox::query()
                ->whereKey($event->id)
                ->where('processing_claim_token', $token)
                ->update([
                    'processing_claim_token' => null,
                    'processing_claimed_at' => null,
                    'next_attempt_at' => $terminal ? null : now()->addSeconds([5, 25, 125][$attempts - 1]),
                    'terminal_at' => $terminal ? now() : null,
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'failure_context' => [
                        'exception' => $exception::class,
                        'attempt' => $attempts,
                    ],
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
