<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Gemini\ProStrandedRecoveryService;
use Illuminate\Console\Command;

class RecoverStrandedGeminiPro extends Command
{
    protected $signature = 'gemini:recover-stranded-pro
                            {--execute : Reset stranded Pro rows and dispatch for re-analysis (default: dry-run)}
                            {--force  : Skip the production confirmation prompt (for scheduled/non-interactive use)}
                            {--limit= : Cap the number of rows processed per run (must be a positive integer)}';

    protected $description = 'Recover stranded Pro (Cambio) Gemini records (analyzed=true but never processed)';

    public function handle(ProStrandedRecoveryService $service): int
    {
        $execute = (bool) $this->option('execute');
        $limitRaw = $this->option('limit');

        // Validate --limit when provided.
        $limit = null;
        if ($limitRaw !== null) {
            if (! ctype_digit((string) $limitRaw) || (int) $limitRaw < 1) {
                $this->error('--limit must be a positive integer (>= 1).');

                return self::FAILURE;
            }

            $limit = (int) $limitRaw;
        }

        // Guard: warn if Gemini integration is disabled.
        if ($execute && ! config('services.gemini.enabled')) {
            $this->warn('Gemini integration is disabled (services.gemini.enabled=false). Rows will NOT be reset.');
            $this->info('Enable Gemini first, then re-run with --execute.');

            return self::SUCCESS;
        }

        // Production confirmation gate (skipped by --force for scheduled use).
        if ($execute && app()->isProduction() && ! $this->option('force')) {
            if (! $this->confirm('This will reset stranded Pro (Cambio) rows and re-queue them for Gemini analysis. Continue?')) {
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
            ],
        );

        if ($report->scanned === 0) {
            $this->info('0 stranded Pro records found. Nothing to do.');
        }

        return self::SUCCESS;
    }
}
