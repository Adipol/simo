<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ResultadoScraping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillZombieResultados extends Command
{
    protected $signature = 'resultados:backfill-zombies
                            {--dry-run : Report zombie count without updating rows}';

    protected $description = 'Backfill zombie rows where gemini_analyzed=true but gemini_is_pep is NULL, setting gemini_is_pep=false';

    public function handle(): int
    {
        $query = ResultadoScraping::where('gemini_analyzed', true)
            ->whereNull('gemini_is_pep');

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: found {$count} zombie row(s). No changes made.");
            Log::info('BackfillZombieResultados dry run completed', ['zombie_count' => $count]);

            return self::SUCCESS;
        }

        $updated = $query->update(['gemini_is_pep' => false]);

        $this->info("Backfill completed: {$updated} zombie row(s) updated to gemini_is_pep=false.");
        Log::info('BackfillZombieResultados completed', ['updated_count' => $updated]);

        return self::SUCCESS;
    }
}
