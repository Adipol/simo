<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DedupeArticulosJob;
use App\Models\ResultadoScraping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety-net command that dispatches DedupeArticulosJob for every
 * resultados_scraping row that has not yet been processed for deduplication.
 *
 * Design §5 (dedupe-safety-net):
 * - Respects kill switch: config('services.dedupe.enabled')
 * - Queries only rows where dedupe_processed_at IS NULL (no chunking needed at current volume)
 * - Dispatches one DedupeArticulosJob per row — job sets onQueue('dedupe') in its constructor
 * - Logs count via Log::channel('gemini') AND $this->info() for operator visibility
 * - Idempotent: rows already stamped are excluded by the WHERE clause
 *
 * Scheduled every 5 minutes in routes/console.php (withoutOverlapping + onOneServer).
 */
class DeduparPendientes extends Command
{
    protected $signature = 'simo:dedupar-pendientes';

    protected $description = 'Despacha DedupeArticulosJob para filas pendientes (dedupe_processed_at IS NULL)';

    public function handle(): int
    {
        if (! config('services.dedupe.enabled', true)) {
            $this->warn('Dedupe está deshabilitado (DEDUPE_ENABLED=false).');

            return self::SUCCESS;
        }

        $ids = ResultadoScraping::query()
            ->whereNull('dedupe_processed_at')
            ->pluck('id');

        $count = $ids->count();

        $this->line("Dedupe: {$count} pendientes");

        foreach ($ids as $id) {
            DedupeArticulosJob::dispatch($id);
        }

        $this->info("Dispatched {$count} dedupe jobs.");

        Log::channel('gemini')->info('dedupe.safety_net.dispatched', [
            'count' => $count,
        ]);

        return self::SUCCESS;
    }
}
