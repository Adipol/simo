<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ResultadoScraping;
use Illuminate\Database\Eloquent\Builder;

class ResultadoScrapingQueryService
{
    public function buildQuery(
        string $busqueda = '',
        string $filtroPais = '',
        string $filtroCategoria = '',
        string $filtroLeido = '',
        string $filtroRelevante = '',
        string $filtroDescartado = '0',
        string $filtroArchivado = '0',
        string $filtroGemini = '',
        string $filtroIds = '',
        string $ordenar = 'fecha_encontrado',
        string $direccion = 'desc',
        ?int $userId = null,
    ): Builder {
        $q = ResultadoScraping::with('sitio')
            ->orderBy($ordenar, $direccion);

        if ($userId !== null) {
            $q->withFeedbackFromUser($userId);
        }

        // ── ID whitelist filter — takes priority, skips unrelated busqueda ────
        if ($filtroIds !== '') {
            $ids = array_values(
                array_filter(
                    array_map('intval', explode(',', $filtroIds)),
                    static fn (int $id): bool => $id > 0,
                )
            );

            if ($ids !== []) {
                $q->whereIn('id', $ids);
            }
        }

        if ($busqueda !== '') {
            $b = '%'.$busqueda.'%';
            $q->where(fn (Builder $s): Builder => $s->where('keyword', 'ilike', $b)
                ->orWhere('titulo', 'ilike', $b)
                ->orWhere('url', 'ilike', $b)
                ->orWhere('contexto', 'ilike', $b));
        }

        if ($filtroPais !== '') {
            $q->where('pais', $filtroPais);
        }

        if ($filtroCategoria !== '') {
            $q->where('categoria', $filtroCategoria);
        }

        if ($filtroLeido !== '') {
            $q->where('leido', (bool) $filtroLeido);
        }

        if ($filtroRelevante !== '') {
            if ($filtroRelevante === 'null') {
                $q->whereNull('relevante');
            } else {
                $q->where('relevante', (bool) $filtroRelevante);
            }
        }

        if ($filtroDescartado === '0') {
            $q->where('descartado', false);
        } elseif ($filtroDescartado === '1') {
            $q->where('descartado', true);
        }

        if ($filtroArchivado === '0') {
            $q->noArchivado();
        } elseif ($filtroArchivado === '1') {
            $q->archivado();
        }

        if ($filtroGemini === 'pending') {
            $q->where('gemini_analyzed', false);
        } elseif ($filtroGemini === 'pep') {
            $q->where('gemini_analyzed', true)->where('gemini_is_pep', true)->where('gemini_categoria', 'PEP');
        } elseif ($filtroGemini === 'opi') {
            $q->where('gemini_analyzed', true)->where('gemini_is_pep', true)->where('gemini_categoria', 'OPI');
        } elseif ($filtroGemini === 'not_pep') {
            $q->where('gemini_analyzed', true)->where('gemini_is_pep', false);
        }

        return $q;
    }
}
