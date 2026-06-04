<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverStrandedGeminiTest extends TestCase
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

    private function createStranded(array $extra = []): ResultadoScraping
    {
        return $this->createRecord(array_merge([
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => null,
            'gemini_is_pep' => null,
            'gemini_error_motivo' => null,
        ], $extra));
    }

    public function test_dry_run_outputs_report_no_mutation(): void
    {
        Queue::fake();

        $s1 = $this->createStranded();
        $s2 = $this->createStranded(['relevante' => true]);

        $this->artisan('gemini:recover-stranded')
            ->assertSuccessful()
            ->expectsOutputToContain('2');

        // Rows untouched
        $s1->refresh();
        $s2->refresh();
        $this->assertTrue($s1->gemini_analyzed);
        $this->assertTrue($s2->gemini_analyzed);

        Queue::assertNothingPushed();
    }

    public function test_execute_flag_resets_and_dispatches(): void
    {
        Queue::fake();

        $s1 = $this->createStranded();
        $s2 = $this->createStranded();

        $this->artisan('gemini:recover-stranded', ['--execute' => true])
            ->assertSuccessful();

        $s1->refresh();
        $s2->refresh();
        $this->assertFalse($s1->gemini_analyzed);
        $this->assertFalse($s2->gemini_analyzed);

        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }

    public function test_no_stranded_records_outputs_clean(): void
    {
        Queue::fake();

        // Only non-stranded records
        $this->createRecord(['gemini_analyzed' => false]);

        $this->artisan('gemini:recover-stranded')
            ->assertSuccessful()
            ->expectsOutputToContain('0');

        Queue::assertNothingPushed();
    }
}
