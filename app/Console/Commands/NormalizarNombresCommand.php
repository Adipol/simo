<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Services\Normalization\NombreNormalizador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NormalizarNombresCommand extends Command
{
    protected $signature = 'simo:normalizar-nombres
                            {--chunk=500 : Número de registros por chunk}
                            {--dry-run : Muestra el conteo sin escribir en BD}
                            {--force : Omite el prompt de confirmación}';

    protected $description = 'Backfill de nombres normalizados para resultados_scraping y clasificaciones_feedback';

    public function handle(NombreNormalizador $normalizador): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // ── Dry-run mode ─────────────────────────────────────────────────────
        if ($dryRun) {
            $countResultados = ResultadoScraping::whereNotNull('gemini_nombre')
                ->whereNull('gemini_nombre_normalizado')
                ->count();

            $countFeedback = ClasificacionFeedback::whereNotNull('corregido_nombre')
                ->whereNull('corregido_nombre_normalizado')
                ->count();

            $total = $countResultados + $countFeedback;
            $this->line("Would update {$total} records ({$countResultados} resultados_scraping, {$countFeedback} clasificaciones_feedback)");

            return self::SUCCESS;
        }

        // ── Count pending records ─────────────────────────────────────────────
        $countResultados = ResultadoScraping::whereNotNull('gemini_nombre')
            ->whereNull('gemini_nombre_normalizado')
            ->count();

        $countFeedback = ClasificacionFeedback::whereNotNull('corregido_nombre')
            ->whereNull('corregido_nombre_normalizado')
            ->count();

        $total = $countResultados + $countFeedback;

        // ── Confirm unless --force ─────────────────────────────────────────────
        if (! $force) {
            if (! $this->confirm("This will update {$total} records. Continue? [y/N]")) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $updatedResultados = 0;
        $updatedFeedback = 0;

        // ── Process resultados_scraping ───────────────────────────────────────
        $this->line('Processing resultados_scraping...');
        $this->output->progressStart($countResultados);

        ResultadoScraping::whereNotNull('gemini_nombre')
            ->whereNull('gemini_nombre_normalizado')
            ->chunkById($chunkSize, function ($records) use ($normalizador, &$updatedResultados) {
                foreach ($records as $record) {
                    try {
                        $dto = $normalizador->normalize($record->gemini_nombre);
                        $record->update(['gemini_nombre_normalizado' => $dto->normalized]);
                        $updatedResultados++;
                    } catch (\Throwable $e) {
                        Log::error('NormalizarNombres: error on resultados_scraping record', [
                            'id' => $record->id,
                            'nombre' => $record->gemini_nombre,
                            'error' => $e->getMessage(),
                        ]);
                        $this->warn("Failed to normalize resultados_scraping record {$record->id}: {$e->getMessage()}");
                    }
                    $this->output->progressAdvance();
                }
            });

        $this->output->progressFinish();
        $this->line("  resultados_scraping: {$updatedResultados} records updated.");

        // ── Process clasificaciones_feedback ─────────────────────────────────
        $this->line('Processing clasificaciones_feedback...');
        $this->output->progressStart($countFeedback);

        ClasificacionFeedback::whereNotNull('corregido_nombre')
            ->whereNull('corregido_nombre_normalizado')
            ->chunkById($chunkSize, function ($records) use ($normalizador, &$updatedFeedback) {
                foreach ($records as $record) {
                    try {
                        $dto = $normalizador->normalize($record->corregido_nombre);
                        $record->update(['corregido_nombre_normalizado' => $dto->normalized]);
                        $updatedFeedback++;
                    } catch (\Throwable $e) {
                        Log::error('NormalizarNombres: error on clasificaciones_feedback record', [
                            'id' => $record->id,
                            'nombre' => $record->corregido_nombre,
                            'error' => $e->getMessage(),
                        ]);
                        $this->warn("Failed to normalize clasificaciones_feedback record {$record->id}: {$e->getMessage()}");
                    }
                    $this->output->progressAdvance();
                }
            });

        $this->output->progressFinish();
        $this->line("  clasificaciones_feedback: {$updatedFeedback} records updated.");

        $totalUpdated = $updatedResultados + $updatedFeedback;
        $this->info("Backfill complete. {$totalUpdated} records updated.");

        return self::SUCCESS;
    }
}
