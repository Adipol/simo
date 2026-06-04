<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverStrandedGeminiTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url'             => 'https://example.com/article-' . uniqid(),
            'keyword'         => 'corrupcion',
            'pais'            => 'BO',
            'categoria'       => 'politica',
            'titulo'          => 'Test Article',
            'contexto'        => 'El ministro firmó un decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function createStranded(array $extra = []): ResultadoScraping
    {
        return $this->createRecord(array_merge([
            'gemini_analyzed'     => true,
            'gemini_analyzed_at'  => null,
            'gemini_is_pep'       => null,
            'gemini_error_motivo' => null,
        ], $extra));
    }

    // =========================================================================
    // Dry-run behaviour
    // =========================================================================

    public function test_dry_run_outputs_stranded_found_count_as_2(): void
    {
        Queue::fake();

        $this->createStranded();
        $this->createStranded(['relevante' => true]);

        $this->artisan('gemini:recover-stranded')
            ->assertSuccessful()
            // Pin the exact labeled row, not just any cell containing "2".
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'DRY-RUN'],
                    ['Stranded found', '2'],
                    ['Reset to pending', '0'],
                    ['Jobs dispatched', '0'],
                    ['Relevante (flagged)', '1'],
                ],
            );

        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_mutate_rows(): void
    {
        Queue::fake();

        $s1 = $this->createStranded();
        $s2 = $this->createStranded(['relevante' => true]);

        $this->artisan('gemini:recover-stranded')->assertSuccessful();

        $s1->refresh();
        $s2->refresh();
        $this->assertTrue($s1->gemini_analyzed);
        $this->assertTrue($s2->gemini_analyzed);
    }

    // =========================================================================
    // Execute behaviour
    // =========================================================================

    public function test_execute_flag_resets_and_dispatches(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $s1 = $this->createStranded();
        $s2 = $this->createStranded();

        $this->artisan('gemini:recover-stranded', ['--execute' => true])
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'EXECUTE'],
                    ['Stranded found', '2'],
                    ['Reset to pending', '2'],
                    ['Jobs dispatched', '1'],
                    ['Relevante (flagged)', '0'],
                ],
            );

        $s1->refresh();
        $s2->refresh();
        $this->assertFalse($s1->gemini_analyzed);
        $this->assertFalse($s2->gemini_analyzed);

        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }

    // =========================================================================
    // Zero stranded records
    // =========================================================================

    public function test_no_stranded_records_outputs_zero_in_stranded_found_row(): void
    {
        Queue::fake();

        // Only a non-stranded record.
        $this->createRecord(['gemini_analyzed' => false]);

        $this->artisan('gemini:recover-stranded')
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'DRY-RUN'],
                    ['Stranded found', '0'],
                    ['Reset to pending', '0'],
                    ['Jobs dispatched', '0'],
                    ['Relevante (flagged)', '0'],
                ],
            )
            ->expectsOutputToContain('0 stranded records found');

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // --limit validation
    // =========================================================================

    public function test_limit_zero_is_rejected_with_failure_exit(): void
    {
        Queue::fake();

        $this->artisan('gemini:recover-stranded', ['--limit' => '0'])
            ->assertFailed()
            ->expectsOutputToContain('--limit must be a positive integer');

        Queue::assertNothingPushed();
    }

    public function test_limit_negative_is_rejected_with_failure_exit(): void
    {
        Queue::fake();

        $this->artisan('gemini:recover-stranded', ['--limit' => '-1'])
            ->assertFailed()
            ->expectsOutputToContain('--limit must be a positive integer');

        Queue::assertNothingPushed();
    }

    public function test_limit_non_numeric_is_rejected_with_failure_exit(): void
    {
        Queue::fake();

        $this->artisan('gemini:recover-stranded', ['--limit' => 'abc'])
            ->assertFailed()
            ->expectsOutputToContain('--limit must be a positive integer');

        Queue::assertNothingPushed();
    }

    public function test_limit_valid_caps_rows_processed(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $this->createStranded();
        $this->createStranded();
        $this->createStranded();

        $this->artisan('gemini:recover-stranded', ['--execute' => true, '--limit' => '2'])
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'EXECUTE'],
                    ['Stranded found', '2'],
                    ['Reset to pending', '2'],
                    ['Jobs dispatched', '1'],
                    ['Relevante (flagged)', '0'],
                ],
            );

        // Exactly 2 rows reset; 1 still stranded.
        $this->assertDatabaseCount('resultados_scraping', 3);
        $this->assertEquals(1, ResultadoScraping::stranded()->count());
        $this->assertEquals(2, ResultadoScraping::where('gemini_analyzed', false)->count());

        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }

    /**
     * FIX A regression: --limit with mixed relevante values must report the
     * relevante count that matches the deterministic (orderBy id) batch,
     * not an independently-evaluated second query.
     */
    public function test_limit_with_mixed_relevante_reports_batch_relevante(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        // 2 relevante=true rows will get the lowest IDs.
        $this->createStranded(['relevante' => true]);
        $this->createStranded(['relevante' => true]);
        $this->createStranded(['relevante' => false]);
        $this->createStranded(['relevante' => false]);

        // limit=2 → the 2 lowest-ID rows (both relevante=true) should be reset.
        $this->artisan('gemini:recover-stranded', ['--execute' => true, '--limit' => '2'])
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'EXECUTE'],
                    ['Stranded found', '2'],
                    ['Reset to pending', '2'],
                    ['Jobs dispatched', '1'],
                    ['Relevante (flagged)', '2'],
                ],
            );

        // 2 rows reset, 2 still stranded.
        $this->assertEquals(2, ResultadoScraping::stranded()->count());
        $this->assertEquals(2, ResultadoScraping::where('gemini_analyzed', false)->count());

        Queue::assertPushed(AnalizarScrapingConFlash::class, 1);
    }

    // =========================================================================
    // Production confirmation gate + --force
    // =========================================================================

    public function test_production_execute_aborts_on_decline_without_force(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);
        // app()->isProduction() checks $app['env'] directly — not APP_ENV config key.
        $this->app['env'] = 'production';

        $this->createStranded();

        $this->artisan('gemini:recover-stranded', ['--execute' => true])
            ->expectsConfirmation(
                'This will reset stranded rows and re-queue them for Gemini analysis. Continue?',
                'no',
            )
            ->assertSuccessful()
            ->expectsOutputToContain('Aborted');

        // Row not touched.
        $this->assertEquals(1, ResultadoScraping::stranded()->count());
        Queue::assertNothingPushed();
    }

    public function test_production_execute_proceeds_on_confirm_without_force(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);
        $this->app['env'] = 'production';

        $s = $this->createStranded();

        $this->artisan('gemini:recover-stranded', ['--execute' => true])
            ->expectsConfirmation(
                'This will reset stranded rows and re-queue them for Gemini analysis. Continue?',
                'yes',
            )
            ->assertSuccessful();

        $s->refresh();
        $this->assertFalse($s->gemini_analyzed);
        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }

    public function test_force_flag_bypasses_production_confirmation(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);
        $this->app['env'] = 'production';

        $s = $this->createStranded();

        // No confirm() interaction expected — --force skips the gate.
        $this->artisan('gemini:recover-stranded', ['--execute' => true, '--force' => true])
            ->assertSuccessful();

        $s->refresh();
        $this->assertFalse($s->gemini_analyzed);
        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }

    // =========================================================================
    // Gemini disabled guard
    // =========================================================================

    public function test_execute_skips_reset_when_gemini_disabled(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', false);

        $s = $this->createStranded();

        $this->artisan('gemini:recover-stranded', ['--execute' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Gemini integration is disabled');

        // Row must NOT be touched.
        $s->refresh();
        $this->assertTrue($s->gemini_analyzed);
        Queue::assertNothingPushed();
    }
}
