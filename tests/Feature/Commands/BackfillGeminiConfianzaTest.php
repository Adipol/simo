<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillGeminiConfianzaTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalyzedRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article-' . uniqid(),
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Ministro firmó decreto',
            'contexto' => 'El ministro de Economía firmó un decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => true,
            'gemini_confianza' => null,
        ], $overrides));
    }

    private function addPersona(ResultadoScraping $record, int $confianza): ResultadoPersona
    {
        return ResultadoPersona::create([
            'resultado_scraping_id' => $record->id,
            'nombre' => 'Persona Test',
            'nombre_normalizado' => null,
            'cargo' => null,
            'categoria' => 'PEP',
            'entidad_tipo' => null,
            'confianza' => $confianza,
            'evento' => null,
            'motivo' => 'Test',
            'threshold_passed' => $confianza >= 70,
        ]);
    }

    public function test_backfill_updates_null_confianza_with_max_persona_confianza(): void
    {
        $record = $this->makeAnalyzedRecord();
        $this->addPersona($record, 88);

        $this->artisan('simo:backfill-gemini-confianza')
            ->assertExitCode(0);

        $record->refresh();
        $this->assertSame(88, $record->gemini_confianza);
    }

    public function test_backfill_skips_articles_with_no_personas(): void
    {
        $record = $this->makeAnalyzedRecord();
        // No personas attached

        $this->artisan('simo:backfill-gemini-confianza')
            ->assertExitCode(0);

        $record->refresh();
        $this->assertNull($record->gemini_confianza);
    }

    public function test_backfill_is_idempotent_when_rerun(): void
    {
        $record = $this->makeAnalyzedRecord();
        $this->addPersona($record, 75);

        // First run
        $this->artisan('simo:backfill-gemini-confianza')
            ->assertExitCode(0);

        $record->refresh();
        $this->assertSame(75, $record->gemini_confianza);

        // Second run — record now has gemini_confianza set, should be skipped
        $this->artisan('simo:backfill-gemini-confianza')
            ->assertExitCode(0);

        // Value should not have changed
        $record->refresh();
        $this->assertSame(75, $record->gemini_confianza);
    }

    public function test_backfill_does_not_touch_unanalyzed_articles(): void
    {
        ResultadoScraping::flushEventListeners();

        $record = ResultadoScraping::create([
            'url' => 'https://example.com/unanalyzed-' . uniqid(),
            'keyword' => 'test',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Sin analizar',
            'contexto' => 'Texto sin analizar',
            'relevance_score' => 50,
            'gemini_analyzed' => false,
            'gemini_confianza' => null,
        ]);

        $this->addPersona($record, 90);

        $this->artisan('simo:backfill-gemini-confianza')
            ->assertExitCode(0);

        $record->refresh();
        // Must remain null — unanalyzed records are excluded from the query
        $this->assertNull($record->gemini_confianza);
    }

    public function test_dry_run_does_not_write_to_db(): void
    {
        $record = $this->makeAnalyzedRecord();
        $this->addPersona($record, 92);

        $this->artisan('simo:backfill-gemini-confianza', ['--dry-run' => true])
            ->assertExitCode(0);

        $record->refresh();
        $this->assertNull($record->gemini_confianza);
    }

    public function test_command_reports_correct_counters(): void
    {
        // 1 record with personas (will be updated)
        $recordWithPersonas = $this->makeAnalyzedRecord();
        $this->addPersona($recordWithPersonas, 80);

        // 1 record without personas (will be skipped)
        $this->makeAnalyzedRecord();

        $this->artisan('simo:backfill-gemini-confianza')
            ->assertExitCode(0)
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Scanned', '2'],
                    ['Updated', '1'],
                    ['Skipped (no personas)', '1'],
                    ['Skipped (already populated)', '0'],
                    ['Mode', 'live'],
                ],
            );
    }

    public function test_command_reports_skipped_already_populated_counter(): void
    {
        // 1 record already populated (will be excluded by whereNull filter — always 0)
        $alreadyPopulated = $this->makeAnalyzedRecord(['gemini_confianza' => 75]);
        $this->addPersona($alreadyPopulated, 75);

        // 1 record still null (will be updated)
        $nullRecord = $this->makeAnalyzedRecord();
        $this->addPersona($nullRecord, 60);

        $this->artisan('simo:backfill-gemini-confianza')
            ->assertExitCode(0)
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Scanned', '1'],
                    ['Updated', '1'],
                    ['Skipped (no personas)', '0'],
                    ['Skipped (already populated)', '0'],
                    ['Mode', 'live'],
                ],
            );
    }
}
