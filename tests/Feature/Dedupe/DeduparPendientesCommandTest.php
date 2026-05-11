<?php

declare(strict_types=1);

namespace Tests\Feature\Dedupe;

use App\Jobs\DedupeArticulosJob;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for simo:dedupar-pendientes command.
 *
 * Mirrors the pattern of AnalizarGeminiCommandTest.
 * Covers SCN-2.1, SCN-2.2, SCN-2.3, SCN-2.4, SCN-5.2, SCN-6.1, SCN-6.2.
 *
 * Design §5 (command pseudocode):
 *  - Kill switch first: config('services.dedupe.enabled')
 *  - Query: whereNull('dedupe_processed_at')->pluck('id')
 *  - Dispatch: DedupeArticulosJob::dispatch($id) — job sets onQueue('dedupe')
 *  - Log: Log::channel('gemini')->info(...) + $this->info(...)
 */
class DeduparPendientesCommandTest extends TestCase
{
    use RefreshDatabase;

    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->sitio = SitioWeb::create([
            'url'    => 'https://dedupe-test.example.com',
            'nombre' => 'DedupeTest',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    private function makeResultado(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url'            => 'https://dedupe-test.example.com/article-' . uniqid(),
            'keyword'        => 'dedupe-test',
            'sitio_id'       => $this->sitio->id,
            'pais'           => 'BO',
            'titulo'         => 'Artículo de prueba dedupe ' . uniqid(),
            'contexto'       => 'Contexto de prueba.',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'          => false,
            'descartado'     => false,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    // ─── T9/SCN-2.1: Only NULL rows dispatched ───────────────────────────────

    /**
     * SCN-2.1 / T9 (RED): Command dispatches exactly N jobs for rows with dedupe_processed_at IS NULL,
     * and skips rows that already have dedupe_processed_at set.
     */
    public function test_it_dispatches_jobs_only_for_rows_with_null_dedupe_processed_at(): void
    {
        config(['services.dedupe.enabled' => true]);

        // 2 pending (NULL)
        $pending1 = $this->makeResultado();
        $pending2 = $this->makeResultado();

        // 1 already processed (non-NULL)
        $processed = $this->makeResultado(['dedupe_processed_at' => now()]);

        $this->artisan('simo:dedupar-pendientes')
            ->assertExitCode(0);

        Queue::assertPushed(DedupeArticulosJob::class, 2);
        Queue::assertPushed(DedupeArticulosJob::class, fn ($job) => $job->resultadoId === $pending1->id);
        Queue::assertPushed(DedupeArticulosJob::class, fn ($job) => $job->resultadoId === $pending2->id);
        Queue::assertNotPushed(DedupeArticulosJob::class, fn ($job) => $job->resultadoId === $processed->id);
    }

    // ─── T10/SCN-2.2, SCN-5.2: Kill switch ──────────────────────────────────

    /**
     * SCN-2.2, SCN-5.2 / T10 (RED): Kill switch prevents all dispatching and outputs warning.
     */
    public function test_it_respects_the_kill_switch_when_dedupe_is_disabled(): void
    {
        config(['services.dedupe.enabled' => false]);

        $this->makeResultado(); // pending row — must NOT be dispatched

        $this->artisan('simo:dedupar-pendientes')
            ->expectsOutputToContain('deshabilitado')
            ->assertExitCode(0);

        Queue::assertNotPushed(DedupeArticulosJob::class);
    }

    // ─── T11/SCN-2.3: No pending rows → no dispatch ──────────────────────────

    /**
     * SCN-2.3 / T11 (RED): When zero rows have NULL dedupe_processed_at, zero jobs are dispatched.
     */
    public function test_it_does_not_dispatch_when_no_pending_rows_exist(): void
    {
        config(['services.dedupe.enabled' => true]);

        // All rows already processed
        $this->makeResultado(['dedupe_processed_at' => now()]);
        $this->makeResultado(['dedupe_processed_at' => now()]);

        $this->artisan('simo:dedupar-pendientes')
            ->assertExitCode(0);

        Queue::assertNotPushed(DedupeArticulosJob::class);
    }

    // ─── T12/SCN-6.1: Logs dispatch count ────────────────────────────────────

    /**
     * SCN-6.1 / T12 (RED): Command outputs the count of dispatched jobs (observable via artisan output).
     */
    public function test_it_logs_the_count_of_dispatched_jobs(): void
    {
        config(['services.dedupe.enabled' => true]);

        $this->makeResultado();
        $this->makeResultado();
        $this->makeResultado();

        $this->artisan('simo:dedupar-pendientes')
            ->expectsOutputToContain('3')
            ->assertExitCode(0);
    }

    // ─── T13/SCN-2.1, SCN-6.2: Dispatches to dedupe queue ───────────────────

    /**
     * SCN-2.1, SCN-6.2 / T13 (RED): Jobs are dispatched to the 'dedupe' queue.
     */
    public function test_it_dispatches_to_the_dedupe_queue(): void
    {
        config(['services.dedupe.enabled' => true]);

        $this->makeResultado();

        $this->artisan('simo:dedupar-pendientes')
            ->assertExitCode(0);

        Queue::assertPushed(DedupeArticulosJob::class, function (DedupeArticulosJob $job): bool {
            return $job->queue === 'dedupe';
        });
    }
}
