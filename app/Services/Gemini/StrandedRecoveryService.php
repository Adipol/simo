<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\RecoveryReportDTO;

class StrandedRecoveryService
{
    /**
     * Recover stranded Gemini records that were marked analyzed=true
     * by the old buggy failed() handler without ever being processed.
     *
     * Dry-run (default): reports counts without mutation.
     * Execute: resets rows to gemini_analyzed=false and dispatches
     * one AnalizarScrapingConFlash job through the existing pipeline.
     */
    public function recover(bool $execute = false, ?int $limit = null): RecoveryReportDTO
    {
        $query = ResultadoScraping::stranded();

        if ($limit !== null) {
            $query->limit($limit);
        }

        $stranded = $query->get();

        $scanned = $stranded->count();
        $relevante = $stranded->where('relevante', true)->count();

        if (! $execute || $scanned === 0) {
            return RecoveryReportDTO::fromArray([
                'scanned' => $scanned,
                'reset' => 0,
                'dispatched' => 0,
                'relevante' => $relevante,
            ]);
        }

        $reset = ResultadoScraping::whereIn('id', $stranded->pluck('id'))
            ->update(['gemini_analyzed' => false]);

        AnalizarScrapingConFlash::dispatch()->onQueue('gemini');

        return RecoveryReportDTO::fromArray([
            'scanned' => $scanned,
            'reset' => $reset,
            'dispatched' => 1,
            'relevante' => $relevante,
        ]);
    }
}
