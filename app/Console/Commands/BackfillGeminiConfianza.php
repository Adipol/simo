<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ResultadoScraping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillGeminiConfianza extends Command
{
    protected $signature = 'simo:backfill-gemini-confianza
                            {--dry-run : Simulate without writing to the database}';

    protected $description = 'Backfill gemini_confianza for analyzed rows where the field is NULL';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mode = $dryRun ? 'dry-run' : 'live';

        $scanned = 0;
        $updated = 0;
        $skippedNoPersonas = 0;

        ResultadoScraping::query()
            ->where('gemini_analyzed', true)
            ->whereNull('gemini_confianza')
            ->with('personas')
            ->chunkById(100, function ($chunk) use ($dryRun, &$scanned, &$updated, &$skippedNoPersonas): void {
                foreach ($chunk as $record) {
                    $scanned++;

                    $personas = $record->personas;

                    if ($personas->isEmpty()) {
                        $skippedNoPersonas++;
                        continue;
                    }

                    $maxConfianza = $personas->max('confianza');

                    if (! $dryRun) {
                        $record->update(['gemini_confianza' => $maxConfianza]);
                    }

                    $updated++;
                }
            });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $scanned],
                ['Updated', $dryRun ? "{$updated} (dry-run)" : (string) $updated],
                ['Skipped (no personas)', $skippedNoPersonas],
                ['Skipped (already populated)', 0],
                ['Mode', $mode],
            ],
        );

        Log::info('BackfillGeminiConfianza completed', [
            'scanned' => $scanned,
            'updated' => $updated,
            'skipped_no_personas' => $skippedNoPersonas,
            'mode' => $mode,
        ]);

        return self::SUCCESS;
    }
}
