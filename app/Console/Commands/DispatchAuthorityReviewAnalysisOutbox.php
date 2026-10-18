<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuthorityReviewAnalysisOutboxService;
use Illuminate\Console\Command;

final class DispatchAuthorityReviewAnalysisOutbox extends Command
{
    protected $signature = 'authority-reviews:dispatch-analysis-outbox {--limit=100}';

    protected $description = 'Dispatch pending authority review analysis outbox events';

    public function handle(AuthorityReviewAnalysisOutboxService $outbox): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $this->info("Dispatched {$outbox->dispatchPending($limit)} authority review analysis event(s).");

        return self::SUCCESS;
    }
}
