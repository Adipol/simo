<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\GeminiUsageLog;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SportsNoiseReportTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = storage_path('logs/sports-noise-report-test.log');
        file_put_contents($this->logPath, '');
        config(['logging.channels.gemini.path' => $this->logPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        parent::tearDown();
    }

    public function test_it_correlates_usage_prefilter_and_indeterminate_evidence_without_writes(): void
    {
        $usage = $this->record(['gemini_motivo' => '[PRE-FILTRO] Sin mención de cargo público en el texto.']);
        $prefilter = $this->record(['gemini_motivo' => '[PRE-FILTRO] Sin mención de cargo público en el texto.']);
        $indeterminate = $this->record();
        GeminiUsageLog::create(['model' => 'flash', 'request_type' => 'analysis', 'resultado_scraping_id' => $usage->id]);
        ResultadoPersona::create(['resultado_scraping_id' => $usage->id, 'nombre' => 'Persona', 'confianza' => 90, 'threshold_passed' => true]);
        file_put_contents($this->logPath, implode("\n", [
            $this->event($usage->id), $this->event($prefilter->id), $this->event($indeterminate->id), $this->event($indeterminate->id, ['reason_codes' => ['role_coach']]),
            $this->event(999999, ['reason_codes' => ['role_coach']]),
            '[2026-08-12] local.INFO: gemini.sports_noise_candidate {bad json}',
        ]));
        $before = $this->snapshot();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('sports:noise-report --format=json --limit=10')->assertExitCode(0);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $payload = app(\App\Services\Gemini\SportsNoiseReportService::class)->report(null, 10, null)->toArray();

        $this->assertSame($before, $this->snapshot());
        $this->assertCount(4, $payload['candidates']);
        $this->assertSame('usage_backed', $payload['candidates'][0]['status']);
        $this->assertSame('prefilter_rejected', $payload['candidates'][1]['status']);
        $this->assertSame('indeterminate', $payload['candidates'][2]['status']);
        $this->assertSame(2, $payload['candidates'][2]['duplicate_count']);
        $this->assertSame(1, $payload['malformed_matching_lines']);
        $this->assertSame(1, $payload['candidates'][0]['threshold_passed_persona_count']);
        $this->assertTrue($payload['candidates'][2]['contradiction_flags'] !== []);
        $this->assertTrue($payload['candidates'][3]['missing_record']);
        $this->assertFalse($payload['human_gate']['enforcement_proposed']);
        $this->assertSame(0, $payload['human_gate']['unsafe_public_opi_candidates']);
        $this->assertTrue(collect($queries)->every(fn (array $query): bool => str_starts_with(strtolower(trim($query['query'])), 'select')));
    }

    public function test_it_rejects_invalid_options_and_reports_missing_retained_logs_safely(): void
    {
        config(['logging.channels.gemini.path' => storage_path('logs/not-present.log')]);
        $this->artisan('sports:noise-report --format=csv')->expectsOutputToContain('--format')->assertExitCode(1);
        $this->artisan('sports:noise-report --limit=0')->expectsOutputToContain('--limit')->assertExitCode(1);
        $this->artisan('sports:noise-report --since=not-a-date')->expectsOutputToContain('--since')->assertExitCode(1);
        $this->artisan('sports:noise-report --format=json')->expectsOutputToContain('retention_gap')->assertExitCode(0);
    }

    public function test_since_excludes_older_events_and_includes_newer_events(): void
    {
        $older = $this->record();
        $newer = $this->record();
        file_put_contents($this->logPath, implode("\n", [
            $this->event($older->id, ['timestamp' => '2026-08-12T09:59:59+00:00']),
            $this->event($newer->id, ['timestamp' => '2026-08-12T10:00:01+00:00']),
        ]));

        $this->assertSame(0, Artisan::call('sports:noise-report', [
            '--format' => 'json',
            '--since' => '2026-08-12T10:00:00+00:00',
        ]));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([$newer->id], array_column($payload['candidates'], 'record_id'));
    }

    public function test_it_decodes_only_the_exact_candidate_event_name(): void
    {
        $accepted = $this->record();
        $suffix = $this->record();
        file_put_contents($this->logPath, implode("\n", [
            $this->event($accepted->id),
            str_replace('gemini.sports_noise_candidate ', 'gemini.sports_noise_candidate_extra ', $this->event($suffix->id)),
        ]));

        $this->assertSame(0, Artisan::call('sports:noise-report', ['--format' => 'json', '--limit' => 10]));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([$accepted->id], array_column($payload['candidates'], 'record_id'));
        $this->assertSame(0, $payload['malformed_matching_lines']);
    }

    public function test_limit_ignores_new_groups_but_preserves_accepted_group_duplicates(): void
    {
        $accepted = $this->record();
        $ignored = $this->record();
        file_put_contents($this->logPath, implode("\n", [
            $this->event($accepted->id),
            $this->event($ignored->id),
            $this->event($accepted->id, ['reason_codes' => ['role_coach']]),
        ]));

        $this->assertSame(0, Artisan::call('sports:noise-report', ['--format' => 'json', '--limit' => 1]));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([$accepted->id], array_column($payload['candidates'], 'record_id'));
        $this->assertSame(2, $payload['candidates'][0]['duplicate_count']);
        $this->assertSame(['conflicting_repeated_events'], $payload['candidates'][0]['contradiction_flags']);
    }

    private function record(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge(['url' => 'https://example.test/'.uniqid(), 'keyword' => 'test', 'pais' => 'BO', 'categoria' => 'test', 'titulo' => 'Test', 'gemini_analyzed' => false], $overrides));
    }

    private function event(int $id, array $overrides = []): string
    {
        return '[2026-08-12 10:00:00] local.INFO: gemini.sports_noise_candidate '.json_encode(array_merge([
            'record_id' => $id,
            'rule_version' => 'sports-noise-v1',
            'timestamp' => '2026-08-12T10:00:00+00:00',
            'reason_codes' => ['club_private'],
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private function snapshot(): array
    {
        return [ResultadoScraping::count(), ResultadoPersona::count(), GeminiUsageLog::count()];
    }
}
