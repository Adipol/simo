<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Gemini;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\RecoveryReportDTO;
use App\Services\Gemini\StrandedRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StrandedRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article-' . uniqid(),
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Test Article',
            'contexto' => 'El ministro firmó un decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    /**
     * Create a stranded record: gemini_analyzed=true, but all terminal
     * columns NULL (analyzed_at, is_pep, error_motivo).
     */
    private function createStranded(array $extra = []): ResultadoScraping
    {
        return $this->createRecord(array_merge([
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => null,
            'gemini_is_pep' => null,
            'gemini_error_motivo' => null,
        ], $extra));
    }

    // ------------------------------------------------------------------
    // Scope tests
    // ------------------------------------------------------------------

    public function test_scope_stranded_matches_only_stranded_records(): void
    {
        // Stranded: analyzed=true, analyzed_at=null, is_pep=null, error_motivo=null
        $stranded = $this->createStranded();

        // Normal analyzed (analyzed_at set) — NOT stranded
        $this->createRecord([
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => now(),
            'gemini_is_pep' => true,
        ]);

        // Errored (error_motivo set) — NOT stranded
        $this->createRecord([
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => null,
            'gemini_is_pep' => null,
            'gemini_error_motivo' => 'Invalid response',
        ]);

        // Pending (analyzed=false) — NOT stranded
        $this->createRecord([
            'gemini_analyzed' => false,
            'gemini_is_pep' => null,
        ]);

        // Pre-filtro / zombie-backfill (is_pep=false) — NOT stranded
        $this->createRecord([
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => null,
            'gemini_is_pep' => false,
            'gemini_error_motivo' => null,
        ]);

        $results = ResultadoScraping::stranded()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($stranded));
    }

    // ------------------------------------------------------------------
    // Service tests
    // ------------------------------------------------------------------

    public function test_dry_run_reports_counts_without_mutation(): void
    {
        Queue::fake();

        $s1 = $this->createStranded(['relevante' => true]);
        $s2 = $this->createStranded();
        $s3 = $this->createStranded();

        $service = new StrandedRecoveryService();
        $report = $service->recover(execute: false);

        $this->assertInstanceOf(RecoveryReportDTO::class, $report);
        $this->assertSame(3, $report->scanned);
        $this->assertSame(0, $report->reset);
        $this->assertSame(0, $report->dispatched);
        $this->assertSame(1, $report->relevante);

        // Rows untouched
        $s1->refresh();
        $s2->refresh();
        $s3->refresh();
        $this->assertTrue($s1->gemini_analyzed);
        $this->assertTrue($s2->gemini_analyzed);
        $this->assertTrue($s3->gemini_analyzed);

        Queue::assertNothingPushed();
    }

    public function test_execute_resets_and_dispatches(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $s1 = $this->createStranded();
        $s2 = $this->createStranded();
        $s3 = $this->createStranded();

        // Controls (must NOT be touched)
        $normalAnalyzed = $this->createRecord([
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => now(),
            'gemini_is_pep' => true,
        ]);
        $pending = $this->createRecord([
            'gemini_analyzed' => false,
        ]);

        $service = new StrandedRecoveryService();
        $report = $service->recover(execute: true);

        $this->assertSame(3, $report->scanned);
        $this->assertSame(3, $report->reset);
        $this->assertSame(1, $report->dispatched);

        // Stranded rows reset to gemini_analyzed=false
        $s1->refresh();
        $s2->refresh();
        $s3->refresh();
        $this->assertFalse($s1->gemini_analyzed);
        $this->assertFalse($s2->gemini_analyzed);
        $this->assertFalse($s3->gemini_analyzed);

        // Controls untouched
        $normalAnalyzed->refresh();
        $pending->refresh();
        $this->assertTrue($normalAnalyzed->gemini_analyzed);
        $this->assertFalse($pending->gemini_analyzed);

        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }

    public function test_idempotent_second_run_finds_zero(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $this->createStranded();
        $this->createStranded();

        $service = new StrandedRecoveryService();

        // First execution resets rows
        $first = $service->recover(execute: true);
        $this->assertSame(2, $first->scanned);
        $this->assertSame(2, $first->reset);

        // Second execution: rows no longer match stranded predicate
        $second = $service->recover(execute: true);
        $this->assertSame(0, $second->scanned);
        $this->assertSame(0, $second->reset);
        $this->assertSame(0, $second->dispatched);
    }

    public function test_relevante_count_in_report(): void
    {
        Queue::fake();

        $this->createStranded(['relevante' => true]);
        $this->createStranded(['relevante' => false]);

        $service = new StrandedRecoveryService();
        $report = $service->recover(execute: false);

        $this->assertSame(2, $report->scanned);
        $this->assertSame(1, $report->relevante);
    }

    /**
     * FIX A regression: with a --limit and mixed relevante values the reported
     * relevante count must correspond to the SAME batch of IDs that are reset,
     * not a second independently-evaluated LIMIT query (which could return a
     * different row set on PostgreSQL without ORDER BY).
     */
    public function test_limit_with_mixed_relevante_batch_correspondence(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        // Create 4 stranded rows: IDs will be ascending.
        // The first 2 (lowest IDs) will have relevante=true.
        $r1 = $this->createStranded(['relevante' => true]);
        $r2 = $this->createStranded(['relevante' => true]);
        $r3 = $this->createStranded(['relevante' => false]);
        $r4 = $this->createStranded(['relevante' => false]);

        $service = new StrandedRecoveryService();

        // Dry-run with limit=2: must report relevante=2 (both rows in the
        // deterministic batch by ascending ID are relevante=true).
        $dryReport = $service->recover(execute: false, limit: 2);

        $this->assertSame(2, $dryReport->scanned);
        $this->assertSame(0, $dryReport->reset);
        $this->assertSame(0, $dryReport->dispatched);
        $this->assertSame(2, $dryReport->relevante, 'Dry-run relevante must match the deterministic 2-row batch');

        // Execute with limit=2: the two lowest-ID rows are reset;
        // relevante in the report must still be 2 (same batch).
        $execReport = $service->recover(execute: true, limit: 2);

        $this->assertSame(2, $execReport->scanned);
        $this->assertSame(2, $execReport->reset);
        $this->assertSame(1, $execReport->dispatched);
        $this->assertSame(2, $execReport->relevante, 'Execute relevante must match the batch that was actually reset');

        // r1 and r2 were reset; r3 and r4 remain stranded.
        $r1->refresh();
        $r2->refresh();
        $r3->refresh();
        $r4->refresh();
        $this->assertFalse($r1->gemini_analyzed, 'Row 1 should be reset');
        $this->assertFalse($r2->gemini_analyzed, 'Row 2 should be reset');
        $this->assertTrue($r3->gemini_analyzed, 'Row 3 should remain stranded');
        $this->assertTrue($r4->gemini_analyzed, 'Row 4 should remain stranded');

        Queue::assertPushed(AnalizarScrapingConFlash::class, 1);
    }

    /**
     * FIX B: when the conditional UPDATE resets 0 rows (concurrent drain),
     * dispatched must be 0 and the job must NOT be pushed.
     */
    public function test_execute_with_zero_reset_skips_dispatch(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        // Create a stranded record so scanned > 0 ...
        $s = $this->createStranded();

        // Simulate concurrent drain: reset the row to non-stranded BEFORE the
        // service executes (i.e. the UPDATE will match 0 rows).
        $s->update([
            'gemini_analyzed'    => false,
            'gemini_analyzed_at' => null,
        ]);

        $service = new StrandedRecoveryService();
        // scanned=0 because the record is no longer stranded.
        $report = $service->recover(execute: true);

        $this->assertSame(0, $report->scanned);
        $this->assertSame(0, $report->reset);
        $this->assertSame(0, $report->dispatched);

        Queue::assertNothingPushed();
    }

    /**
     * FIX B (limited path): concurrent drain during the limited path must also
     * result in dispatched=0.
     */
    public function test_execute_limit_with_zero_reset_skips_dispatch(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        // Create a stranded record, then drain it before the service runs.
        $s = $this->createStranded();

        // Manually force the row out of the stranded state after it will be
        // plucked as a candidate but before the UPDATE re-checks.
        // We do this by directly updating to a non-stranded state:
        $s->update(['gemini_analyzed_at' => now()]);

        $service = new StrandedRecoveryService();

        // The $batchIds pluck will find 0 rows (already not stranded) → reset=0.
        $report = $service->recover(execute: true, limit: 5);

        $this->assertSame(0, $report->scanned);
        $this->assertSame(0, $report->reset);
        $this->assertSame(0, $report->dispatched);

        Queue::assertNothingPushed();
    }
}
