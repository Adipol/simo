<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dashboard\DashboardSummaryService;
use Illuminate\Console\Command;

class BustDashboardSummaryCommand extends Command
{
    protected $signature = 'dashboard:summary:bust';

    protected $description = 'Bust the dashboard summary cache key so the next request re-computes';

    public function handle(DashboardSummaryService $service): int
    {
        $service->bust();
        $this->info('✓ Dashboard summary cache busted.');

        return self::SUCCESS;
    }
}
