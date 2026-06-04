<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Gemini;

use App\Jobs\AnalizarCambioConPro;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Services\Gemini\DTOs\RecoveryReportDTO;
use App\Services\Gemini\ProStrandedRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProStrandedRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fuente(): Fuente
    {
        static $fuente = null;

        if ($fuente === null || ! Fuente::find($fuente->id)) {
            $fuente = Fuente::create([
                'url'       => 'https://gobierno.bo/test-pro-recovery',
                'nombre'    => 'Test Ministry',
                'organismo' => 'Test Organism',
                'pais'      => 'BO',
            ]);
        }

        return $fuente;
    }

    private function createCambio(array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id'       => $this->fuente()->id,
            'fecha'           => now(),
            'diff_texto'      => "-Old Person\n+New Person",
            'gemini_analyzed' => false,
        ], $overrides));
    }

    /**
     * Stranded Pro record: analyzed=true but analyzed_at IS NULL (failed()-stranded).
     */
    private function createStranded(array $extra = []): Cambio
    {
        return $this->createCambio(array_merge([
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => null,
        ], $extra));
    }

    // =========================================================================
    // Task 5.1 — scopeStranded on Cambio
    // =========================================================================

    public function test_scope_stranded_returns_only_stranded_rows(): void
    {
        // Stranded: analyzed=true, analyzed_at=null
        $stranded = $this->createStranded();

        // Normal analyzed (analyzed_at set) — NOT stranded
        $this->createCambio([
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => now(),
        ]);

        // Pending — NOT stranded
        $this->createCambio([
            'gemini_analyzed' => false,
        ]);

        $results = Cambio::stranded()->get();

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($stranded));
    }

    public function test_scope_stranded_excludes_terminal_failed_rows(): void
    {
        // Terminal-failed: marcarFallido sets both analyzed=true AND analyzed_at=now()
        $this->createCambio([
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => now(),
            'gemini_analisis_json' => null,  // no result, but analyzed_at is set
        ]);

        // Pending
        $this->createCambio(['gemini_analyzed' => false]);

        // No stranded rows
        $this->assertSame(0, Cambio::stranded()->count());
    }

    // =========================================================================
    // Task 5.2 — Dry-run: no mutation
    // =========================================================================

    public function test_pro_recovery_service_dry_run_no_mutation(): void
    {
        Queue::fake();

        $s1 = $this->createStranded();
        $s2 = $this->createStranded();

        $service = new ProStrandedRecoveryService;
        $report = $service->recover(execute: false);

        $this->assertInstanceOf(RecoveryReportDTO::class, $report);
        $this->assertSame(2, $report->scanned);
        $this->assertSame(0, $report->reset);
        $this->assertSame(0, $report->dispatched);

        // Rows untouched
        $s1->refresh();
        $s2->refresh();
        $this->assertTrue($s1->gemini_analyzed);
        $this->assertTrue($s2->gemini_analyzed);
        $this->assertNull($s1->gemini_analyzed_at);
        $this->assertNull($s2->gemini_analyzed_at);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // Task 5.4 — Execute: resets and dispatches
    // =========================================================================

    public function test_pro_recovery_service_execute_resets_and_dispatches(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $s1 = $this->createStranded();
        $s2 = $this->createStranded();

        // Control: normal analyzed — must NOT be touched
        $normal = $this->createCambio([
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => now(),
        ]);

        // Control: pending — must NOT be touched
        $pending = $this->createCambio(['gemini_analyzed' => false]);

        $service = new ProStrandedRecoveryService;
        $report = $service->recover(execute: true);

        $this->assertSame(2, $report->scanned);
        $this->assertSame(2, $report->reset);
        $this->assertSame(1, $report->dispatched);

        $s1->refresh();
        $s2->refresh();
        $this->assertFalse($s1->gemini_analyzed);
        $this->assertFalse($s2->gemini_analyzed);

        // Controls untouched
        $normal->refresh();
        $pending->refresh();
        $this->assertTrue($normal->gemini_analyzed);
        $this->assertFalse($pending->gemini_analyzed);

        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    // =========================================================================
    // Task 5.4 — Idempotent: second run finds zero
    // =========================================================================

    public function test_pro_recovery_service_idempotent(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $this->createStranded();
        $this->createStranded();

        $service = new ProStrandedRecoveryService;

        $first = $service->recover(execute: true);
        $this->assertSame(2, $first->reset);

        $second = $service->recover(execute: true);
        $this->assertSame(0, $second->scanned);
        $this->assertSame(0, $second->reset);
        $this->assertSame(0, $second->dispatched);
    }

    // =========================================================================
    // Conditional UPDATE safety: zero-reset skips dispatch
    // =========================================================================

    public function test_execute_with_zero_reset_skips_dispatch(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        // Create a stranded row, then drain it before the service runs.
        $s = $this->createStranded();
        $s->update([
            'gemini_analyzed'    => false,
            'gemini_analyzed_at' => null,
        ]);

        $service = new ProStrandedRecoveryService;
        $report = $service->recover(execute: true);

        $this->assertSame(0, $report->scanned);
        $this->assertSame(0, $report->reset);
        $this->assertSame(0, $report->dispatched);

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // --limit support
    // =========================================================================

    public function test_limit_caps_rows_processed(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $this->createStranded();
        $this->createStranded();
        $this->createStranded();

        $service = new ProStrandedRecoveryService;
        $report = $service->recover(execute: true, limit: 2);

        $this->assertSame(2, $report->scanned);
        $this->assertSame(2, $report->reset);
        $this->assertSame(1, $report->dispatched);

        // 1 row still stranded
        $this->assertSame(1, Cambio::stranded()->count());
    }
}
