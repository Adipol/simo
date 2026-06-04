<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Gemini;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\RecoveryReportDTO;
use App\Services\Gemini\StrandedRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
