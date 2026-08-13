<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Gemini\SportsNoiseReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class SportsNoiseReport extends Command
{
    protected $signature = 'sports:noise-report {--since=} {--limit=50} {--rule-version=} {--format=table}';

    protected $description = 'Read-only correlation report for sports-noise candidate telemetry';

    public function handle(SportsNoiseReportService $service): int
    {
        $limit = $this->option('limit');
        if (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 500) {
            $this->error('--limit must be an integer from 1 to 500.');

            return self::FAILURE;
        }
        if (! in_array($this->option('format'), ['table', 'json'], true)) {
            $this->error('--format must be table or json.');

            return self::FAILURE;
        }
        try {
            $since = $this->option('since') === null ? null : CarbonImmutable::parse((string) $this->option('since'));
        } catch (\Throwable) {
            $this->error('--since must be a valid date/time.');

            return self::FAILURE;
        }
        $report = $service->report($since, (int) $limit, $this->option('rule-version'));
        if ($this->option('format') === 'json') {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $this->table(['Record', 'Rule', 'Status', 'Usage', 'Duplicates'], array_map(fn (array $row): array => [$row['record_id'], $row['rule_version'], $row['status'], $row['usage_count'], $row['duplicate_count']], $report->candidates));
        $this->line('retention_gap='.($report->retentionGap ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
