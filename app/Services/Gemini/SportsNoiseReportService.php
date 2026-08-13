<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\GeminiUsageLog;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\SportsNoiseReportDTO;
use Carbon\CarbonInterface;

final class SportsNoiseReportService
{
    private const PREFILTER_MOTIVE = '[PRE-FILTRO] Sin mención de cargo público en el texto.';

    private const MAX_FILES = 15;

    private const MAX_LINES_PER_FILE = 20000;

    public function report(?CarbonInterface $since, int $limit, ?string $ruleVersion): SportsNoiseReportDTO
    {
        [$groups, $malformed, $retentionGap] = $this->events($since, $limit, $ruleVersion);
        $ids = array_values(array_unique(array_column($groups, 'record_id')));
        $records = ResultadoScraping::query()->with('personas')->whereIn('id', $ids)->get()->keyBy('id');
        $usage = GeminiUsageLog::query()->whereIn('resultado_scraping_id', $ids)->get()->groupBy('resultado_scraping_id');
        $candidates = [];

        foreach ($groups as $group) {
            $record = $records->get($group['record_id']);
            $usageCount = $usage->get($group['record_id'], collect())->count();
            $motive = $record?->gemini_motivo;
            $hasConflicts = count(array_unique($group['fingerprints'])) > 1;
            $status = $usageCount > 0 ? 'usage_backed' : ($motive === self::PREFILTER_MOTIVE ? 'prefilter_rejected' : 'indeterminate');
            $candidates[] = [
                'record_id' => $group['record_id'], 'rule_version' => $group['rule_version'], 'status' => $status,
                'usage_count' => $usageCount, 'gemini_motivo' => $motive, 'gemini_analyzed_at' => $record?->gemini_analyzed_at?->toIso8601String(),
                'gemini_error_motivo' => $record?->gemini_error_motivo, 'threshold_passed_persona_count' => $record?->personas->where('threshold_passed', true)->count() ?? 0,
                'missing_record' => $record === null, 'missing_log' => false, 'duplicate_count' => $group['count'], 'malformed_matching_lines' => 0,
                'contradiction_flags' => array_values(array_filter([
                    $hasConflicts ? 'conflicting_repeated_events' : null,
                    $usageCount > 0 && $motive === self::PREFILTER_MOTIVE ? 'usage_and_prefilter_motive' : null,
                ])),
            ];
        }

        return new SportsNoiseReportDTO($candidates, $malformed, $retentionGap, [
            'reviewed_candidates' => 0, 'required_reviews' => 50, 'unsafe_public_opi_candidates' => 0,
            'enforcement_proposed' => false, 'recall_claim' => 'none_for_unlogged_pass_open_records',
        ]);
    }

    /** @return array{array<int, array{record_id:int,rule_version:string,count:int,fingerprints:array<int,string>}>,int,bool} */
    private function events(?CarbonInterface $since, int $limit, ?string $ruleVersion): array
    {
        $path = (string) config('logging.channels.gemini.path');
        $paths = array_slice(array_unique(array_merge([$path], glob($path.'-*') ?: [])), 0, self::MAX_FILES);
        $groups = [];
        $malformed = 0;
        $readable = false;
        foreach ($paths as $file) {
            if (! is_readable($file) || ($handle = fopen($file, 'r')) === false) {
                continue;
            }
            $readable = true;
            $lines = 0;
            while (($line = fgets($handle)) !== false && ++$lines <= self::MAX_LINES_PER_FILE) {
                if (! str_contains($line, ': gemini.sports_noise_candidate {')) {
                    continue;
                }
                $json = substr($line, strpos($line, '{'));
                try {
                    $event = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $malformed++;

                    continue;
                }
                if (! is_array($event) || ! isset($event['record_id'], $event['rule_version'], $event['timestamp']) || ! is_numeric($event['record_id'])) {
                    $malformed++;

                    continue;
                }
                if (($ruleVersion !== null && $event['rule_version'] !== $ruleVersion) || ($since !== null && $event['timestamp'] < $since->toIso8601String())) {
                    continue;
                }
                $key = ((int) $event['record_id']).'|'.$event['rule_version'];
                if (! isset($groups[$key]) && count($groups) >= $limit) {
                    continue;
                }
                $groups[$key] ??= ['record_id' => (int) $event['record_id'], 'rule_version' => (string) $event['rule_version'], 'count' => 0, 'fingerprints' => []];
                $groups[$key]['count']++;
                $groups[$key]['fingerprints'][] = hash('sha256', json_encode([$event['reason_codes'] ?? [], $event['escape_codes'] ?? [], $event['title_fingerprint'] ?? null], JSON_THROW_ON_ERROR));
            }
            fclose($handle);
        }
        usort($groups, fn (array $a, array $b): int => [$a['record_id'], $a['rule_version']] <=> [$b['record_id'], $b['rule_version']]);

        return [array_values($groups), $malformed, ! $readable];
    }
}
