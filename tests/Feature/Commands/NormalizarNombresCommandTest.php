<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizarNombresCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermisosSeeder::class);
    }

    private function createResultadoWithName(string $nombre): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create([
            'url' => 'https://test.com/article-'.uniqid(),
            'keyword' => 'test',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_nombre' => $nombre,
            'gemini_nombre_normalizado' => null,
        ]);
    }

    private function createFeedbackWithName(string $nombre): ClasificacionFeedback
    {
        $user = User::factory()->create();
        $resultado = $this->createResultadoWithName('Juan Perez');
        // Clear normalizado set by GeminiFiltroService integration (if any)
        $resultado->update(['gemini_nombre_normalizado' => 'Juan Perez']);

        return ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => 'incorrecto',
            'clasificacion_snapshot' => [],
            'corregido_nombre' => $nombre,
            'corregido_nombre_normalizado' => null,
        ]);
    }

    // ─── 7.1: Command existence and signature ────────────────────────────────

    public function test_command_can_be_called_without_error(): void
    {
        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->assertExitCode(0);
    }

    public function test_command_has_dry_run_option(): void
    {
        $this->artisan('simo:normalizar-nombres', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_command_has_force_option(): void
    {
        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->assertExitCode(0);
    }

    public function test_command_has_chunk_option(): void
    {
        $this->artisan('simo:normalizar-nombres', ['--chunk' => 100, '--force' => true])
            ->assertExitCode(0);
    }

    // ─── 7.3: Dry-run mode ────────────────────────────────────────────────────

    public function test_dry_run_outputs_count_without_writing(): void
    {
        $this->createResultadoWithName('Dr. Juan Pérez');
        $this->createResultadoWithName('Ing. Pedro López');

        $this->artisan('simo:normalizar-nombres', ['--dry-run' => true])
            ->expectsOutputToContain('2')
            ->assertExitCode(0);

        // No records should be updated
        $this->assertDatabaseMissing('resultados_scraping', [
            'gemini_nombre' => 'Dr. Juan Pérez',
            'gemini_nombre_normalizado' => 'Juan Pérez',
        ]);
    }

    public function test_dry_run_shows_would_update_message(): void
    {
        $this->createResultadoWithName('Dr. Juan Pérez');

        $this->artisan('simo:normalizar-nombres', ['--dry-run' => true])
            ->expectsOutputToContain('Would update')
            ->assertExitCode(0);
    }

    // ─── 7.4: Force skips prompt ──────────────────────────────────────────────

    public function test_force_option_begins_processing_without_prompt(): void
    {
        $this->createResultadoWithName('Dr. Juan Pérez');

        // With --force, should complete without any prompt/confirmation
        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('resultados_scraping', [
            'gemini_nombre' => 'Dr. Juan Pérez',
            'gemini_nombre_normalizado' => 'Juan Pérez',
        ]);
    }

    // ─── 7.6: Both tables processed ───────────────────────────────────────────

    public function test_backfill_processes_both_resultados_and_feedback_tables(): void
    {
        // Create records in both tables
        $this->createResultadoWithName('Dr. Juan Pérez');
        $this->createFeedbackWithName('Sra. María García');

        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('resultados_scraping', [
            'gemini_nombre' => 'Dr. Juan Pérez',
            'gemini_nombre_normalizado' => 'Juan Pérez',
        ]);
        $this->assertDatabaseHas('clasificaciones_feedback', [
            'corregido_nombre' => 'Sra. María García',
            'corregido_nombre_normalizado' => 'María García',
        ]);
    }

    public function test_backfill_reports_count_for_each_table(): void
    {
        $this->createResultadoWithName('Dr. Juan Pérez');
        $this->createFeedbackWithName('Sra. María García');

        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->expectsOutputToContain('resultados_scraping')
            ->expectsOutputToContain('clasificaciones_feedback')
            ->assertExitCode(0);
    }

    // ─── 7.8: Idempotency ─────────────────────────────────────────────────────

    public function test_second_run_processes_zero_records(): void
    {
        $this->createResultadoWithName('Dr. Juan Pérez');

        // First run
        $this->artisan('simo:normalizar-nombres', ['--force' => true]);

        // Second run — should report 0 updates
        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->expectsOutputToContain('0')
            ->assertExitCode(0);
    }

    public function test_records_with_existing_normalized_are_skipped(): void
    {
        // Create record that already has normalized value
        $sitio = SitioWeb::factory()->create();
        ResultadoScraping::create([
            'url' => 'https://test.com/already-normalized',
            'keyword' => 'test',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_nombre' => 'Existing Name',
            'gemini_nombre_normalizado' => 'Already Normalized',
        ]);

        // Run backfill — record should not change
        $this->artisan('simo:normalizar-nombres', ['--force' => true]);

        $this->assertDatabaseHas('resultados_scraping', [
            'gemini_nombre' => 'Existing Name',
            'gemini_nombre_normalizado' => 'Already Normalized',
        ]);
    }

    // ─── 7.10: Chunk option ───────────────────────────────────────────────────

    public function test_chunk_option_processes_records(): void
    {
        // Create 5 records
        for ($i = 1; $i <= 5; $i++) {
            $this->createResultadoWithName("Dr. Person {$i}");
        }

        // With chunk=2, should still process all 5 records
        $this->artisan('simo:normalizar-nombres', ['--chunk' => 2, '--force' => true])
            ->assertExitCode(0);

        // All 5 should be normalized
        $this->assertDatabaseMissing('resultados_scraping', [
            'gemini_nombre_normalizado' => null,
            'gemini_is_pep' => true,
        ]);
    }

    // ─── 7.12: Error resilience ───────────────────────────────────────────────

    public function test_backfill_continues_after_individual_record_error(): void
    {
        // Create valid records
        $this->createResultadoWithName('Dr. Juan Pérez');
        $this->createResultadoWithName('Ing. Pedro López');

        // The command should complete successfully
        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->assertExitCode(0);

        // Both should be normalized
        $this->assertDatabaseHas('resultados_scraping', [
            'gemini_nombre' => 'Dr. Juan Pérez',
            'gemini_nombre_normalizado' => 'Juan Pérez',
        ]);
        $this->assertDatabaseHas('resultados_scraping', [
            'gemini_nombre' => 'Ing. Pedro López',
            'gemini_nombre_normalizado' => 'Pedro López',
        ]);
    }

    // ─── 8.3: Integration test ────────────────────────────────────────────────

    public function test_backfill_existing_records_both_tables_updated(): void
    {
        // Create multiple records in both tables
        $this->createResultadoWithName('Dr. Juan Pérez');
        $this->createResultadoWithName('MARÍA GARCIA');
        $this->createFeedbackWithName('Prof. Ana Torres');

        $this->artisan('simo:normalizar-nombres', ['--force' => true])
            ->expectsOutputToContain('Backfill complete')
            ->assertExitCode(0);

        $this->assertDatabaseHas('resultados_scraping', [
            'gemini_nombre' => 'Dr. Juan Pérez',
            'gemini_nombre_normalizado' => 'Juan Pérez',
        ]);
        $this->assertDatabaseHas('resultados_scraping', [
            'gemini_nombre' => 'MARÍA GARCIA',
            'gemini_nombre_normalizado' => 'María Garcia',
        ]);
        $this->assertDatabaseHas('clasificaciones_feedback', [
            'corregido_nombre' => 'Prof. Ana Torres',
            'corregido_nombre_normalizado' => 'Ana Torres',
        ]);
    }
}
