<?php

declare(strict_types=1);

namespace App\Services\Export;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports ResultadoScraping articles to CSV.
 *
 * Format (per user DECISION 5):
 *  - 1 row per persona detected (1 article with 2 personas → 2 CSV rows)
 *  - 1 article with 0 personas → 1 row with empty persona fields
 *  - Persona data comes from resultado_personas, NOT legacy gemini_* columns
 *
 * Columns: id, fecha, sitio, url, titulo, persona_nombre, persona_cargo,
 *          persona_categoria, persona_confianza, persona_threshold_passed, gemini_motivo
 */
class ResultadosCsvExporter
{
    public function stream(Builder $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ID', 'Fecha', 'Sitio', 'URL', 'Keyword', 'Pais', 'Categoria',
                'Titulo', 'Score', 'Gemini_Analizado', 'Gemini_Motivo',
                'Persona_Nombre', 'Persona_Cargo', 'Persona_Categoria',
                'Persona_Confianza', 'Persona_Threshold_Passed',
            ]);

            $query->with(['sitio', 'personas'])->chunk(200, function ($rows) use ($handle): void {
                foreach ($rows as $r) {
                    $personas = $r->personas;

                    if ($personas->isEmpty()) {
                        // 1 article with 0 personas → 1 row, empty persona fields
                        fputcsv($handle, $this->buildRow($r, null));
                    } else {
                        // 1 article with N personas → N rows
                        foreach ($personas as $persona) {
                            fputcsv($handle, $this->buildRow($r, $persona));
                        }
                    }
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Build a single CSV row for an article + optional persona.
     *
     * @param  \App\Models\ResultadoScraping  $r
     * @param  \App\Models\ResultadoPersona|null  $persona
     * @return array<string|int|null>
     */
    private function buildRow(object $r, ?object $persona): array
    {
        return [
            $r->id,
            $r->fecha_encontrado->format('Y-m-d H:i:s'),
            $r->sitio?->nombre ?? '',
            $r->url,
            $r->keyword,
            $r->pais ?? '',
            $r->categoria ?? '',
            $r->titulo ?? '',
            $r->relevance_score,
            $r->gemini_analyzed ? 'Si' : 'No',
            $r->gemini_motivo ?? '',
            $persona?->nombre ?? '',
            $persona?->cargo ?? '',
            $persona?->categoria ?? '',
            $persona?->confianza ?? '',
            $persona !== null ? ($persona->threshold_passed ? 'Si' : 'No') : '',
        ];
    }
}
