<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\AnalizarCambioConPro;
use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverStrandedGeminiProTest extends TestCase
{
    use RefreshDatabase;

    private function fuente(): Fuente
    {
        static $fuente = null;

        if ($fuente === null || ! Fuente::find($fuente->id)) {
            $fuente = Fuente::create([
                'url'       => 'https://gobierno.bo/test-pro-cmd-recovery',
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
            'diff_texto'      => "-Old\n+New",
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function createStranded(array $extra = []): Cambio
    {
        return $this->createCambio(array_merge([
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => null,
        ], $extra));
    }

    // =========================================================================
    // Dry-run
    // =========================================================================

    public function test_dry_run_outputs_stranded_count(): void
    {
        Queue::fake();

        $this->createStranded();
        $this->createStranded();

        $this->artisan('gemini:recover-stranded-pro')
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'DRY-RUN'],
                    ['Stranded found', '2'],
                    ['Reset to pending', '0'],
                    ['Jobs dispatched', '0'],
                ],
            );

        Queue::assertNothingPushed();
    }

    public function test_dry_run_does_not_mutate_rows(): void
    {
        Queue::fake();

        $s1 = $this->createStranded();
        $s2 = $this->createStranded();

        $this->artisan('gemini:recover-stranded-pro')->assertSuccessful();

        $s1->refresh();
        $s2->refresh();
        $this->assertTrue($s1->gemini_analyzed);
        $this->assertTrue($s2->gemini_analyzed);
    }

    // =========================================================================
    // Execute
    // =========================================================================

    public function test_execute_flag_resets_and_dispatches(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $s1 = $this->createStranded();
        $s2 = $this->createStranded();

        $this->artisan('gemini:recover-stranded-pro', ['--execute' => true])
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'EXECUTE'],
                    ['Stranded found', '2'],
                    ['Reset to pending', '2'],
                    ['Jobs dispatched', '1'],
                ],
            );

        $s1->refresh();
        $s2->refresh();
        $this->assertFalse($s1->gemini_analyzed);
        $this->assertFalse($s2->gemini_analyzed);

        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    // =========================================================================
    // Zero stranded
    // =========================================================================

    public function test_no_stranded_records_outputs_zero(): void
    {
        Queue::fake();

        $this->createCambio(['gemini_analyzed' => false]);

        $this->artisan('gemini:recover-stranded-pro')
            ->assertSuccessful()
            ->expectsOutputToContain('0 stranded Pro records found');

        Queue::assertNothingPushed();
    }

    // =========================================================================
    // --limit validation
    // =========================================================================

    public function test_limit_zero_is_rejected(): void
    {
        $this->artisan('gemini:recover-stranded-pro', ['--limit' => '0'])
            ->assertFailed()
            ->expectsOutputToContain('--limit must be a positive integer');
    }

    public function test_limit_negative_is_rejected(): void
    {
        $this->artisan('gemini:recover-stranded-pro', ['--limit' => '-5'])
            ->assertFailed()
            ->expectsOutputToContain('--limit must be a positive integer');
    }

    public function test_limit_non_numeric_is_rejected(): void
    {
        $this->artisan('gemini:recover-stranded-pro', ['--limit' => 'abc'])
            ->assertFailed()
            ->expectsOutputToContain('--limit must be a positive integer');
    }

    public function test_limit_valid_caps_rows_processed(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);

        $this->createStranded();
        $this->createStranded();
        $this->createStranded();

        $this->artisan('gemini:recover-stranded-pro', ['--execute' => true, '--limit' => '2'])
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Mode', 'EXECUTE'],
                    ['Stranded found', '2'],
                    ['Reset to pending', '2'],
                    ['Jobs dispatched', '1'],
                ],
            );

        $this->assertSame(1, Cambio::stranded()->count());
        $this->assertSame(2, Cambio::where('gemini_analyzed', false)->count());

        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    // =========================================================================
    // Production confirmation gate + --force
    // =========================================================================

    public function test_production_execute_aborts_on_decline(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);
        $this->app['env'] = 'production';

        $this->createStranded();

        $this->artisan('gemini:recover-stranded-pro', ['--execute' => true])
            ->expectsConfirmation(
                'This will reset stranded Pro (Cambio) rows and re-queue them for Gemini analysis. Continue?',
                'no',
            )
            ->assertSuccessful()
            ->expectsOutputToContain('Aborted');

        $this->assertSame(1, Cambio::stranded()->count());
        Queue::assertNothingPushed();
    }

    public function test_force_flag_bypasses_production_confirmation(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', true);
        $this->app['env'] = 'production';

        $s = $this->createStranded();

        $this->artisan('gemini:recover-stranded-pro', ['--execute' => true, '--force' => true])
            ->assertSuccessful();

        $s->refresh();
        $this->assertFalse($s->gemini_analyzed);
        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    // =========================================================================
    // Gemini disabled guard
    // =========================================================================

    public function test_execute_skips_reset_when_gemini_disabled(): void
    {
        Queue::fake();
        Config::set('services.gemini.enabled', false);

        $s = $this->createStranded();

        $this->artisan('gemini:recover-stranded-pro', ['--execute' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Gemini integration is disabled');

        $s->refresh();
        $this->assertTrue($s->gemini_analyzed);
        Queue::assertNothingPushed();
    }
}
