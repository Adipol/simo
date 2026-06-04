<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Gemini\StrandedRecoveryService;
use Illuminate\Console\Command;

class RecoverStrandedGemini extends Command
{
    protected $signature = 'gemini:recover-stranded
                            {--execute : Reset stranded rows and dispatch for re-analysis (default: dry-run)}
                            {--limit= : Cap the number of rows processed per run}';

    protected $description = 'Recover SLP-4 stranded Gemini records (analyzed=true but never processed)';

    public function handle(StrandedRecoveryService $service): int
    {
        $execute = (bool) $this->option('execute');
        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : null;

        if ($execute && app()->isProduction()) {
            if (! $this->confirm('This will reset stranded rows and re-queue them for Gemini analysis. Continue?')) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $report = $service->recover(execute: $execute, limit: $limit);

        $mode = $execute ? 'EXECUTE' : 'DRY-RUN';

        $this->table(
            ['Metric', 'Count'],
            [
                ['Mode', $mode],
                ['Stranded found', (string) $report->scanned],
                ['Reset to pending', (string) $report->reset],
                ['Jobs dispatched', (string) $report->dispatched],
                ['Relevante (flagged)', (string) $report->relevante],
            ],
        );

        if ($report->scanned === 0) {
            $this->info('0 stranded records found. Nothing to do.');
        }

        return self::SUCCESS;
    }
}
