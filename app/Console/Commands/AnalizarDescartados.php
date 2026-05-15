<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DescartadosAnalisisService;
use Illuminate\Console\Command;

/**
 * CLI analytics report for discarded scraping results.
 *
 * Consumes DescartadosAnalisisService to display precision metrics,
 * top problematic keywords/sitios, temporal drift, Gemini confidence
 * correlation, and auto-generated recommendations.
 *
 * feedback-loop-from-descartados · design §Command
 *
 * Permission: SKIPPED — CLI execution implies SSH-level trust.
 * Mirror of LimpiarLogs.php and BackfillZombieResultados.php patterns.
 */
final class AnalizarDescartados extends Command
{
    protected $signature = 'simo:analizar-descartados
                            {--dias=30 : Ventana de análisis en días}
                            {--categoria= : Filtrar por categoría (PEP-designacion, OPI-crimen, etc.)}
                            {--keyword= : Análisis detallado de UNA keyword específica}
                            {--min-sample=5 : Threshold mínimo de ocurrencias por keyword/sitio}
                            {--no-cache : Bypass cache (lectura on-demand)}';

    protected $description = 'Análisis de descartados (uso CLI; sin gate de permiso — acceso SSH implica trust).';

    private const string SEPARATOR = '================================================================================';

    public function handle(DescartadosAnalisisService $service): int
    {
        $dias      = (int) $this->option('dias');
        $minSample = (int) $this->option('min-sample');
        $noCache   = (bool) $this->option('no-cache');
        $keyword   = $this->option('keyword') ? (string) $this->option('keyword') : null;

        $this->renderHeader($dias);
        $this->renderResumen($service, $dias, $minSample, $noCache);

        // When --keyword is specified, show keyword-specific analysis only
        if ($keyword !== null) {
            $this->renderKeywordDetail($service, $keyword, $dias, $minSample, $noCache);

            return self::SUCCESS;
        }

        $this->renderLemas($service, $dias, $minSample, $noCache);
        $this->renderSitios($service, $dias, $minSample, $noCache);
        $this->renderDrift($service, $dias, $noCache);
        $this->renderConfianza($service, $dias, $noCache);
        $this->renderRecomendaciones($service, $dias, $minSample, $noCache);

        return self::SUCCESS;
    }

    // ─── Private renderers ────────────────────────────────────────────────────

    private function renderHeader(int $dias): void
    {
        $this->line(self::SEPARATOR);
        $this->line("  SIMO — Análisis de Descartados (últimos {$dias} días)");
        $this->line(self::SEPARATOR);
        $this->line('');
    }

    private function renderResumen(
        DescartadosAnalisisService $service,
        int $dias,
        int $minSample,
        bool $noCache,
    ): void {
        $dto = $service->precisionGeneral(dias: $dias, skipCache: $noCache);

        $this->info('RESUMEN GENERAL');

        $this->line("  Total procesados:    {$dto->totalProcesados}");
        $this->line("  Descartados:         {$dto->totalDescartados}");
        $this->line("  Relevantes:          {$dto->totalRelevantes}");
        $this->line("  Archivados:          {$dto->totalArchivados}");

        if ($dto->precisionPct === null) {
            $this->warn("  Precisión real:      datos insuficientes — {$dto->insufficientReason}");
        } else {
            $this->line("  Precisión real:      {$dto->precisionPct}%");
        }

        $this->line('');
    }

    private function renderLemas(
        DescartadosAnalisisService $service,
        int $dias,
        int $minSample,
        bool $noCache,
    ): void {
        $this->line(self::SEPARATOR);
        $this->line("  TOP LEMAS PROBLEMÁTICOS — ordenados por % descartado DESC");
        $this->line(self::SEPARATOR);
        $this->line('');

        $lemas = $service->topLemasProblematicos(dias: $dias, minSample: $minSample, skipCache: $noCache);

        if ($lemas->isEmpty()) {
            $this->line('  Sin datos en la ventana solicitada.');
            $this->line('');

            return;
        }

        $this->table(
            ['Keyword', 'Total', 'Descartados', 'Relevantes', '% Descartado'],
            $lemas->map(fn ($dto) => [
                $dto->keyword,
                $dto->total,
                $dto->descartados,
                $dto->relevantes,
                number_format($dto->pctDescartado, 1) . '%',
            ])->toArray()
        );

        $this->line('');
    }

    private function renderSitios(
        DescartadosAnalisisService $service,
        int $dias,
        int $minSample,
        bool $noCache,
    ): void {
        $this->line(self::SEPARATOR);
        $this->line("  TOP SITIOS PROBLEMÁTICOS");
        $this->line(self::SEPARATOR);
        $this->line('');

        $sitios = $service->topSitiosProblematicos(dias: $dias, minSample: $minSample, skipCache: $noCache);

        if ($sitios->isEmpty()) {
            $this->line('  Sin datos en la ventana solicitada.');
            $this->line('');

            return;
        }

        $this->table(
            ['Sitio', 'Total', 'Descartados', '% Descartado'],
            $sitios->map(fn ($dto) => [
                $dto->sitioNombre,
                $dto->total,
                $dto->descartados,
                number_format($dto->pctDescartado, 1) . '%',
            ])->toArray()
        );

        $this->line('');
    }

    private function renderDrift(
        DescartadosAnalisisService $service,
        int $dias,
        bool $noCache,
    ): void {
        $this->line(self::SEPARATOR);
        $this->line("  DRIFT — cambio últimos {$dias}d vs {$dias}d anteriores");
        $this->line(self::SEPARATOR);
        $this->line('');

        $drift = $service->driftPorKeyword(ventanaRecent: $dias, skipCache: $noCache);

        if ($drift->isEmpty()) {
            $this->line('  Sin datos en la ventana solicitada.');
            $this->line('');

            return;
        }

        $this->table(
            ['Keyword', 'Actual %', 'Anterior %', 'Delta (ppt)'],
            $drift->map(fn ($dto) => [
                $dto->keyword,
                $dto->pctActual !== null ? number_format($dto->pctActual, 1) . '%' : 'N/D',
                $dto->pctAnterior !== null ? number_format($dto->pctAnterior, 1) . '%' : 'N/D',
                $dto->driftPpt !== null ? number_format($dto->driftPpt, 1) . 'ppt' : 'N/D',
            ])->toArray()
        );

        $this->line('');
    }

    private function renderConfianza(
        DescartadosAnalisisService $service,
        int $dias,
        bool $noCache,
    ): void {
        $this->line(self::SEPARATOR);
        $this->line('  GEMINI CONFIANZA vs % DESCARTADO HUMANO');
        $this->line(self::SEPARATOR);
        $this->line('');

        $buckets = $service->confianzaGeminiVsDescartado(dias: $dias, skipCache: $noCache);

        if ($buckets->isEmpty()) {
            $this->line('  Sin datos en la ventana solicitada.');
            $this->line('');

            return;
        }

        $this->table(
            ['Bucket Confianza', 'Total', '% Descartado'],
            $buckets->map(fn ($dto) => [
                $dto->bucket,
                $dto->total,
                number_format($dto->pctDescartado, 1) . '%',
            ])->toArray()
        );

        $this->line('');
    }

    private function renderKeywordDetail(
        DescartadosAnalisisService $service,
        string $keyword,
        int $dias,
        int $minSample,
        bool $noCache,
    ): void {
        $this->line(self::SEPARATOR);
        $this->line("  DETALLE — Keyword: {$keyword}");
        $this->line(self::SEPARATOR);
        $this->line('');

        $lemas = $service->topLemasProblematicos(dias: $dias, minSample: 1, skipCache: $noCache);
        $filtered = $lemas->filter(fn ($dto) => $dto->keyword === $keyword);

        if ($filtered->isEmpty()) {
            $this->warn("  Sin datos para la keyword '{$keyword}' en la ventana de {$dias} días.");
            $this->line('');

            return;
        }

        $this->table(
            ['Keyword', 'Total', 'Descartados', 'Relevantes', '% Descartado'],
            $filtered->map(fn ($dto) => [
                $dto->keyword,
                $dto->total,
                $dto->descartados,
                $dto->relevantes,
                number_format($dto->pctDescartado, 1) . '%',
            ])->toArray()
        );

        $this->line('');
    }

    private function renderRecomendaciones(
        DescartadosAnalisisService $service,
        int $dias,
        int $minSample,
        bool $noCache,
    ): void {
        $this->line(self::SEPARATOR);
        $this->line('  RECOMENDACIONES AUTOMÁTICAS');
        $this->line(self::SEPARATOR);
        $this->line('');

        $lemas = $service->topLemasProblematicos(dias: $dias, minSample: $minSample, skipCache: $noCache);

        $problematicos = $lemas->filter(fn ($dto) => $dto->pctDescartado >= 80.0 && $dto->total >= 10);

        if ($problematicos->isEmpty()) {
            $this->info('  ✓ Sin lemas con alta tasa de descartado (≥80% con N≥10).');
        } else {
            foreach ($problematicos as $dto) {
                $this->warn("  ⚠ El lema '{$dto->keyword}' tiene {$dto->pctDescartado}% descartado — revisá el filtro de esta keyword.");
            }
        }

        $this->line('');
    }
}
