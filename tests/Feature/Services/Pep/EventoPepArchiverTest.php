<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Pep;

use App\Models\ResultadoScraping;
use App\Services\Pep\EventoPepArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature tests for EventoPepArchiver.
 *
 * Covers:
 *   - archivar() sets archivado_at on all requested IDs
 *   - Idempotency: already-archived rows are not modified, count reflects only new archives
 *   - Snapshot semantics: rows inserted AFTER the snapshot are not affected
 *   - Structured logging via Log::info with 'panel-peps.archive' channel
 *   - Edge cases: empty array returns 0, nonexistent IDs return 0
 */
class EventoPepArchiverTest extends TestCase
{
    use RefreshDatabase;

    private EventoPepArchiver $archiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiver = app(EventoPepArchiver::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a ResultadoScraping with archivado_at = null (default).
     */
    private function crearResultado(): ResultadoScraping
    {
        return ResultadoScraping::factory()->create(['archivado_at' => null]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2.A.1 + 2.A.2 — archivar sets archivado_at on all IDs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * archivar() must set archivado_at on every row in the given ID list.
     */
    public function test_archive_group_sets_archivado_at_on_all_resultado_ids(): void
    {
        $r1 = $this->crearResultado();
        $r2 = $this->crearResultado();
        $r3 = $this->crearResultado();

        // Use startOfSecond because SQLite stores datetime without sub-second precision.
        // Without truncating, $before might be slightly ahead of the stored value.
        $before = now()->startOfSecond();

        $count = $this->archiver->archivar([$r1->id, $r2->id, $r3->id]);

        $this->assertSame(3, $count, 'Should return 3 — all newly archived');

        foreach ([$r1, $r2, $r3] as $row) {
            $row->refresh();
            $this->assertNotNull($row->archivado_at, "Row {$row->id} archivado_at should not be null");
            $this->assertTrue(
                $row->archivado_at->gte($before),
                "Row {$row->id} archivado_at should be >= start of test",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2.A.3 + 2.A.4 — idempotency
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Already-archived rows must not be modified; only newly archived rows are counted.
     */
    public function test_archive_group_is_idempotent_when_rows_already_archived(): void
    {
        $alreadyArchived = ResultadoScraping::factory()->create([
            'archivado_at' => '2026-04-01 00:00:00',
        ]);
        $r2 = $this->crearResultado();
        $r3 = $this->crearResultado();

        $count = $this->archiver->archivar([$alreadyArchived->id, $r2->id, $r3->id]);

        $this->assertSame(2, $count, 'Should return 2 — only the two newly archived rows');

        // Row 1: archivado_at must remain exactly as set (not overwritten)
        $alreadyArchived->refresh();
        $this->assertEquals(
            '2026-04-01 00:00:00',
            $alreadyArchived->archivado_at->format('Y-m-d H:i:s'),
            'Pre-existing archivado_at must not be overwritten',
        );

        // Rows 2 & 3 must now be archived
        foreach ([$r2, $r3] as $row) {
            $row->refresh();
            $this->assertNotNull($row->archivado_at, "Row {$row->id} should now be archived");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2.A.5 + 2.A.6 — snapshot semantics
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * archivar() must only affect the explicitly passed IDs.
     * A row inserted AFTER the snapshot is captured must remain untouched.
     */
    public function test_archive_does_not_affect_rows_inserted_after_snapshot(): void
    {
        $r1 = $this->crearResultado();
        $r2 = $this->crearResultado();
        $r3 = $this->crearResultado();

        // Snapshot captured BEFORE row 4 is inserted
        $snapshot = [$r1->id, $r2->id, $r3->id];

        // Row 4 arrives between snapshot and the archive call
        $r4 = $this->crearResultado();

        // Pass the snapshot — NOT a fresh query of current state
        $count = $this->archiver->archivar($snapshot);

        $this->assertSame(3, $count);

        foreach ([$r1, $r2, $r3] as $row) {
            $row->refresh();
            $this->assertNotNull($row->archivado_at, "Row {$row->id} should be archived");
        }

        $r4->refresh();
        $this->assertNull($r4->archivado_at, 'Row inserted after snapshot must remain unarchived');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2.A.7 — structured logging
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * archivar() must log a structured event with context about what was archived.
     */
    public function test_archive_logs_structured_event(): void
    {
        $r1 = $this->crearResultado();
        $r2 = $this->crearResultado();

        Log::shouldReceive('info')
            ->once()
            ->with(
                'panel-peps.archive',
                \Mockery::on(function (array $context) use ($r1, $r2): bool {
                    return $context['count_requested'] === 2
                        && $context['count_newly_archived'] === 2
                        && in_array($r1->id, $context['resultado_ids'], true)
                        && in_array($r2->id, $context['resultado_ids'], true);
                })
            );

        $this->archiver->archivar([$r1->id, $r2->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2.A.8 — edge cases
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * archivar() with an empty array must do nothing and return 0.
     */
    public function test_archive_with_empty_array_does_nothing_returns_zero(): void
    {
        // Ensure no rows are modified
        $r1 = $this->crearResultado();

        $count = $this->archiver->archivar([]);

        $this->assertSame(0, $count, 'Empty array should return 0');

        $r1->refresh();
        $this->assertNull($r1->archivado_at, 'No row should be touched when ID list is empty');
    }

    /**
     * archivar() with nonexistent IDs must return 0 and touch nothing.
     */
    public function test_archive_with_nonexistent_ids_returns_zero(): void
    {
        $count = $this->archiver->archivar([99999, 99998]);

        $this->assertSame(0, $count, 'Nonexistent IDs should produce 0 affected rows');
    }
}
