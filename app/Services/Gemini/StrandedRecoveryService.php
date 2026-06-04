<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\RecoveryReportDTO;
use Illuminate\Support\Facades\DB;

class StrandedRecoveryService
{
    /**
     * Recover stranded Gemini records that were marked analyzed=true
     * by the old buggy failed() handler without ever being processed.
     *
     * Dry-run (default): reports counts without mutation.
     * Execute: resets rows to gemini_analyzed=false and dispatches
     * one AnalizarScrapingConFlash job through the existing pipeline.
     *
     * The reset is performed as a single conditional UPDATE that re-asserts
     * the stranded predicate inside a transaction, so rows that were
     * legitimately processed between the scan and the write are never clobbered.
     *
     * Returns a RecoveryReportDTO with DB-level aggregate counts (no full hydration).
     */
    public function recover(bool $execute = false, ?int $limit = null): RecoveryReportDTO
    {
        // Build the base stranded query (no hydration — counts only).
        // Note: ->count() ignores LIMIT on the query builder (it wraps in a subquery),
        // so we cap the counts manually when a limit is in play.
        $baseQuery = ResultadoScraping::stranded();

        $totalStranded = (clone $baseQuery)->count();
        $scanned = $limit !== null ? min($totalStranded, $limit) : $totalStranded;

        // For the limited path, materialize the target IDs ONCE with a deterministic
        // order so that the relevante count and the reset UPDATE operate on the exact
        // same batch.  Without an ORDER BY, two LIMIT queries on PostgreSQL can return
        // different row sets, making the reported relevante figure inconsistent with
        // the rows actually reset.
        if ($limit !== null) {
            $batchIds = (clone $baseQuery)->orderBy('id')->limit($limit)->pluck('id');
            $relevante = ResultadoScraping::whereIn('id', $batchIds)
                ->where('relevante', true)
                ->count();
        } else {
            $batchIds = null;
            $relevante = (clone $baseQuery)->where('relevante', true)->count();
        }

        if (! $execute || $scanned === 0) {
            return RecoveryReportDTO::fromArray([
                'scanned'    => $scanned,
                'reset'      => 0,
                'dispatched' => 0,
                'relevante'  => $relevante,
            ]);
        }

        if (! config('services.gemini.enabled')) {
            // Gemini is disabled — do NOT reset rows into a void.
            return RecoveryReportDTO::fromArray([
                'scanned'    => $scanned,
                'reset'      => 0,
                'dispatched' => 0,
                'relevante'  => $relevante,
            ]);
        }

        // Atomic conditional UPDATE: re-applies the stranded predicate so only
        // rows that are *still* stranded at write time are reset.
        // Wrapping in a transaction prevents concurrent runs from double-dispatching.
        $reset = DB::transaction(function () use ($limit, $batchIds): int {
            if ($limit !== null) {
                // Use the pre-materialized, deterministically-ordered ID set so that
                // this UPDATE targets exactly the same rows as the relevante scan above.
                // Re-asserting the stranded predicate (whereIn + scope columns) ensures
                // rows legitimately processed between the scan and the write are skipped.
                if ($batchIds === null || $batchIds->isEmpty()) {
                    return 0;
                }

                return ResultadoScraping::whereIn('id', $batchIds)
                    ->where('gemini_analyzed', true)
                    ->whereNull('gemini_analyzed_at')
                    ->whereNull('gemini_is_pep')
                    ->whereNull('gemini_error_motivo')
                    ->update(['gemini_analyzed' => false]);
            }

            return ResultadoScraping::stranded()->update(['gemini_analyzed' => false]);
        });

        // FIX B: if the conditional UPDATE reset 0 rows (e.g. all candidates were
        // drained concurrently), skip dispatch and report dispatched=0 to keep
        // the metric honest.
        if ($reset === 0) {
            return RecoveryReportDTO::fromArray([
                'scanned'    => $scanned,
                'reset'      => 0,
                'dispatched' => 0,
                'relevante'  => $relevante,
            ]);
        }

        // Dispatch one kickoff job that self-chains through the throttled pipeline
        // until all newly-pending rows are processed.
        AnalizarScrapingConFlash::dispatch()->onQueue('gemini');

        return RecoveryReportDTO::fromArray([
            'scanned'    => $scanned,
            'reset'      => $reset,
            'dispatched' => 1,
            'relevante'  => $relevante,
        ]);
    }
}
