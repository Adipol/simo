<?php

declare(strict_types=1);

namespace App\Services\Export;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultadosCsvExporter
{
    public function stream(Builder $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ID', 'Keyword', 'URL', 'Sitio', 'Pais', 'Categoria',
                'Titulo', 'Contexto', 'Relevance', 'Fecha',
                'Gemini_Analizado', 'Gemini_PEP', 'Gemini_Categoria',
                'Gemini_Nombre', 'Gemini_Cargo', 'Gemini_Confianza',
            ]);

            $query->chunk(500, function ($rows) use ($handle): void {
                foreach ($rows as $r) {
                    fputcsv($handle, [
                        $r->id,
                        $r->keyword,
                        $r->url,
                        $r->sitio?->nombre ?? '',
                        $r->pais,
                        $r->categoria ?? '',
                        $r->titulo ?? '',
                        $r->contexto ?? '',
                        $r->relevance_score,
                        $r->fecha_encontrado->format('Y-m-d H:i:s'),
                        $r->gemini_analyzed ? 'Si' : 'No',
                        $r->gemini_is_pep ? 'Si' : 'No',
                        $r->gemini_categoria ?? '',
                        $r->gemini_nombre ?? '',
                        $r->gemini_cargo ?? '',
                        $r->gemini_confianza ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
