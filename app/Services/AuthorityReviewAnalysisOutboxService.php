<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessAuthorityReviewAnalysis;
use App\Models\AuthorityReviewAnalysisOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

final class AuthorityReviewAnalysisOutboxService
{
    public function dispatchPending(int $limit = 100): int
    {
        $dispatched = 0;

        AuthorityReviewAnalysisOutbox::query()
            ->whereNull('processed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('dispatched_at')
                    ->orWhere('dispatched_at', '<=', now()->subMinutes(10));
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $eventId) use (&$dispatched): void {
                if ($this->dispatch($eventId)) {
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    public function dispatch(int $eventId): bool
    {
        $token = (string) Str::uuid();
        $claimed = AuthorityReviewAnalysisOutbox::query()
            ->whereKey($eventId)
            ->whereNull('processed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('dispatched_at')
                    ->orWhere('dispatched_at', '<=', now()->subMinutes(10));
            })
            ->where(function (Builder $query): void {
                $query->whereNull('dispatch_claimed_at')
                    ->orWhere('dispatch_claimed_at', '<=', now()->subMinutes(10));
            })
            ->update([
                'dispatch_claim_token' => $token,
                'dispatch_claimed_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $event = AuthorityReviewAnalysisOutbox::query()->findOrFail($eventId);

        try {
            ProcessAuthorityReviewAnalysis::dispatch($event->id, $event->idempotency_key);
            AuthorityReviewAnalysisOutbox::query()
                ->whereKey($eventId)
                ->where('dispatch_claim_token', $token)
                ->update([
                    'dispatched_at' => now(),
                    'dispatch_claim_token' => null,
                    'dispatch_claimed_at' => null,
                ]);
        } catch (Throwable $exception) {
            AuthorityReviewAnalysisOutbox::query()
                ->whereKey($eventId)
                ->where('dispatch_claim_token', $token)
                ->update([
                    'dispatch_claim_token' => null,
                    'dispatch_claimed_at' => null,
                ]);

            throw $exception;
        }

        return true;
    }
}
