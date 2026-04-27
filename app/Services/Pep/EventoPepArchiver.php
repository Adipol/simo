<?php

declare(strict_types=1);

namespace App\Services\Pep;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EventoPepArchiver
 *
 * Archivar una lista explícita de resultado_scraping IDs.
 * Pasá un snapshot del grupo (NO re-consultes la BD adentro).
 * Idempotente: filas ya archivadas no se tocan.
 */
final class EventoPepArchiver
{
    /**
     * Archivar una lista explícita de resultado_scraping IDs.
     *
     * - Solo afecta los IDs del snapshot recibido (no re-consulta la BD).
     * - Idempotente: filas con archivado_at != null no se tocan.
     * - Retorna la cantidad de filas recién archivadas (no las ya archivadas).
     *
     * @param  array<int>  $resultadoIds  Snapshot de IDs a archivar
     * @return int  Cantidad de filas recién archivadas
     */
    public function archivar(array $resultadoIds): int
    {
        if ($resultadoIds === []) {
            return 0;
        }

        $affected = DB::transaction(function () use ($resultadoIds): int {
            return \App\Models\ResultadoScraping::whereIn('id', $resultadoIds)
                ->whereNull('archivado_at')
                ->update(['archivado_at' => now()]);
        });

        Log::info('panel-peps.archive', [
            'count_requested'      => count($resultadoIds),
            'count_newly_archived' => $affected,
            'resultado_ids'        => $resultadoIds,
        ]);

        return $affected;
    }
}
