<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class PipelineHealthDTO
{
    public function __construct(
        public ScraperStatusDTO $scraper,
        public ScraperStatusDTO $pep_monitor,
        public QueueDepthDTO $queues,
        public LatencyDTO $latency,
        public GeminiQuotaDTO $quota,
        public bool $can_see_details,
    ) {}
}
