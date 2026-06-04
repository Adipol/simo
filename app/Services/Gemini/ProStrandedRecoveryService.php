<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Jobs\AnalizarCambioConPro;
use App\Models\Cambio;
use App\Services\Gemini\DTOs\RecoveryReportDTO;
use Illuminate\Support\Facades\DB;

class ProStrandedRecoveryService
{
    /**
     * Recover Pro (Cambio) stranded records that were set to gemini_analyzed=true
     * by the old buggy AnalizarCambioConPro::failed() without ever being processed.
     *
     * Mirrors StrandedRecoveryService for Flash (ResultadoScraping).
     * Predicate: gemini_analyzed=true AND gemini_analyzed_at IS NULL.
     *
     * Dry-run (default): reports counts without mutation.
     * Execute: resets rows to gemini_analyzed=false and dispatches one
     * AnalizarCambioConPro job through the existing pipeline.
     *
     * The reset is performed as a single conditional UPDATE that re-asserts
     * the stranded predicate inside a transaction, so rows that were legitimately
     * processed between the scan and the write are never clobbered.
     */
    public function recover(bool $execute = false, ?int $limit = null): RecoveryReportDTO
    {
        $baseQuery = Cambio::stranded();

        $totalStranded = (clone $baseQuery)->count();
        $scanned = $limit !== null ? min($totalStranded, $limit) : $totalStranded;

        // For the limited path, materialize the target IDs ONCE with a deterministic
        // order so that the count and the reset UPDATE operate on the exact same batch.
        if ($limit !== null) {
            $batchIds = (clone $baseQuery)->orderBy('id')->limit($limit)->pluck('id');
        } else {
            $batchIds = null;
        }

        if (! $execute || $scanned === 0) {
            return RecoveryReportDTO::fromArray([
                'scanned'    => $scanned,
                'reset'      => 0,
                'dispatched' => 0,
                // relevante is a Flash-only concept (ResultadoScraping has a relevante column;
                // Cambio does not). Always 0 here; RecoverStrandedGeminiPro does not display it.
                'relevante'  => 0,
            ]);
        }

        if (! config('services.gemini.enabled')) {
            return RecoveryReportDTO::fromArray([
                'scanned'    => $scanned,
                'reset'      => 0,
                'dispatched' => 0,
                'relevante'  => 0, // Flash-only field; unused on the Pro path.
            ]);
        }

        // Atomic conditional UPDATE: re-applies the stranded predicate inside a
        // transaction so rows legitimately processed since the scan are not reset.
        $reset = DB::transaction(function () use ($limit, $batchIds): int {
            if ($limit !== null) {
                if ($batchIds === null || $batchIds->isEmpty()) {
                    return 0;
                }

                return Cambio::whereIn('id', $batchIds)
                    ->where('gemini_analyzed', true)
                    ->whereNull('gemini_analyzed_at')
                    ->update(['gemini_analyzed' => false]);
            }

            return Cambio::stranded()->update(['gemini_analyzed' => false]);
        });

        // If the conditional UPDATE reset 0 rows (concurrent drain), skip dispatch.
        if ($reset === 0) {
            return RecoveryReportDTO::fromArray([
                'scanned'    => $scanned,
                'reset'      => 0,
                'dispatched' => 0,
                'relevante'  => 0, // Flash-only field; unused on the Pro path.
            ]);
        }

        // Dispatch one kickoff job that self-chains through the throttled pipeline.
        AnalizarCambioConPro::dispatch()->onQueue('gemini');

        return RecoveryReportDTO::fromArray([
            'scanned'    => $scanned,
            'reset'      => $reset,
            'dispatched' => 1,
            'relevante'  => 0, // Flash-only field; unused on the Pro path.
        ]);
    }
}
