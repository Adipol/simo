<?php

declare(strict_types=1);

namespace App\Services\Pep;

use App\Services\Pep\DTOs\EventoPepDTO;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Executes the deduplicated PEP events query for the panel.
 *
 * Groups resultado_personas rows by (nombre_normalizado, evento, día)
 * joined to resultados_scraping, returning one EventoPepDTO per group.
 *
 * Uses a manual count subquery for pagination because paginate() on a
 * GROUP BY query counts by groups, not by rows — the subquery wrapping
 * approach gives the correct total number of groups.
 */
final class ResultadoPersonaQueryService
{
    /**
     * Get paginated event groups for the panel.
     *
     * @param  string|null  $categoria            Filter by 'PEP' or 'OPI' (null = all).
     * @param  string|null  $fechaDesde           ISO date lower bound on DATE(fecha_encontrado).
     * @param  string|null  $fechaHasta           ISO date upper bound on DATE(fecha_encontrado).
     * @param  bool         $mostrarSinClasificar When false, exclude rows with evento IS NULL.
     * @param  int          $perPage              Groups per page.
     * @param  int          $page                 Current page number.
     * @return LengthAwarePaginator<EventoPepDTO>
     */
    public function getEventosAgrupados(
        ?string $categoria = null,
        ?string $fechaDesde = null,
        ?string $fechaHasta = null,
        bool $mostrarSinClasificar = false,
        int $perPage = 25,
        int $page = 1,
    ): LengthAwarePaginator {
        $driver = DB::getDriverName();

        $query = $this->buildBaseQuery($driver, $categoria, $fechaDesde, $fechaHasta, $mostrarSinClasificar);

        // Manual count via subquery (paginate() on GROUP BY returns wrong total)
        $countQuery  = DB::table(DB::raw("({$query->toSql()}) as sub"))
            ->mergeBindings($query);
        $total = $countQuery->count();

        // Fetch the page
        $offset = ($page - 1) * $perPage;
        $rows   = (clone $query)
            ->orderBy('dia', 'desc')
            ->orderBy('ultima_fecha_encontrado', 'desc')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $items = $rows->map(fn (object $row) => $this->mapRowToDto($row, $driver))->all();

        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
        );
    }

    /**
     * Build the base GROUP BY query builder (no ORDER BY, LIMIT, or OFFSET).
     * Returns a QueryBuilder that can be cloned for count and data queries.
     */
    private function buildBaseQuery(
        string $driver,
        ?string $categoria,
        ?string $fechaDesde,
        ?string $fechaHasta,
        bool $mostrarSinClasificar,
    ): \Illuminate\Database\Query\Builder {
        $query = DB::table('resultado_personas as rp')
            ->join('resultados_scraping as rs', 'rs.id', '=', 'rp.resultado_scraping_id')
            ->whereRaw('rp.threshold_passed = ?', [true])
            ->whereNotNull('rp.nombre_normalizado')
            ->whereNull('rs.archivado_at'); // ocultar grupos totalmente archivados

        // Exclude NULL evento unless toggle is on
        if (! $mostrarSinClasificar) {
            $query->whereNotNull('rp.evento');
        }

        // Optional categoria filter
        if ($categoria !== null && $categoria !== '') {
            $query->where('rp.categoria', $categoria);
        }

        // Optional date range filter on the grouped day
        if ($fechaDesde !== null && $fechaDesde !== '') {
            $query->whereRaw('DATE(rs.fecha_encontrado) >= ?', [$fechaDesde]);
        }

        if ($fechaHasta !== null && $fechaHasta !== '') {
            $query->whereRaw('DATE(rs.fecha_encontrado) <= ?', [$fechaHasta]);
        }

        // Apply driver-specific aggregates in SELECT + GROUP BY
        if ($driver === 'pgsql') {
            $this->applyPostgresSelect($query);
        } else {
            $this->applySqliteSelect($query);
        }

        return $query;
    }

    /**
     * Apply PostgreSQL-specific SELECT and GROUP BY.
     *
     * Uses ARRAY_AGG, bool_and, and FILTER (all Postgres-only).
     * cargo uses a correlated subquery to get the most recent non-null cargo.
     */
    private function applyPostgresSelect(\Illuminate\Database\Query\Builder $query): void
    {
        $query->selectRaw("
            rp.nombre_normalizado,
            rp.evento,
            rp.categoria,
            DATE(rs.fecha_encontrado) AS dia,
            COUNT(*) AS num_fuentes,
            ARRAY_AGG(rs.id ORDER BY rs.fecha_encontrado DESC) AS resultado_ids,
            ARRAY_AGG(DISTINCT rs.sitio_id) AS sitio_ids,
            (
                SELECT rp2.cargo
                FROM resultado_personas rp2
                JOIN resultados_scraping rs2 ON rs2.id = rp2.resultado_scraping_id
                WHERE rp2.nombre_normalizado = rp.nombre_normalizado
                  AND (rp2.evento IS NOT DISTINCT FROM rp.evento)
                  AND DATE(rs2.fecha_encontrado) = DATE(rs.fecha_encontrado)
                  AND rp2.cargo IS NOT NULL
                ORDER BY rs2.fecha_encontrado DESC
                LIMIT 1
            ) AS cargo,
            MAX(rs.fecha_encontrado) AS ultima_fecha_encontrado,
            bool_and(rs.archivado_at IS NOT NULL) AS todos_archivados
        ")->groupByRaw('rp.nombre_normalizado, rp.evento, rp.categoria, DATE(rs.fecha_encontrado)');
    }

    /**
     * Apply SQLite-compatible SELECT and GROUP BY (test environment).
     *
     * SQLite lacks ARRAY_AGG, bool_and, and FILTER.
     * We use GROUP_CONCAT for IDs and MAX for cargo (alphabetical, not most-recent).
     * This is intentionally "good enough" for test assertions on grouping behavior.
     */
    private function applySqliteSelect(\Illuminate\Database\Query\Builder $query): void
    {
        $query->selectRaw("
            rp.nombre_normalizado,
            rp.evento,
            rp.categoria,
            DATE(rs.fecha_encontrado) AS dia,
            COUNT(*) AS num_fuentes,
            GROUP_CONCAT(rs.id) AS resultado_ids,
            GROUP_CONCAT(DISTINCT rs.sitio_id) AS sitio_ids,
            MAX(CASE WHEN rp.cargo IS NOT NULL THEN rp.cargo END) AS cargo,
            MAX(rs.fecha_encontrado) AS ultima_fecha_encontrado,
            CASE WHEN COUNT(CASE WHEN rs.archivado_at IS NULL THEN 1 END) = 0 THEN 1 ELSE 0 END AS todos_archivados
        ")->groupByRaw('rp.nombre_normalizado, rp.evento, rp.categoria, DATE(rs.fecha_encontrado)');
    }

    /**
     * Map a raw DB row to an EventoPepDTO.
     * Handles driver-specific result formats (Postgres arrays vs comma-separated strings).
     */
    private function mapRowToDto(object $row, string $driver): EventoPepDTO
    {
        $resultadoIds = $this->parseIntArray($row->resultado_ids ?? '', $driver);
        $sitios       = $this->parseIntArray($row->sitio_ids ?? '', $driver);

        $dia                  = CarbonImmutable::parse($row->dia);
        $ultimaFechaEncontrado = CarbonImmutable::parse($row->ultima_fecha_encontrado);

        // todos_archivados is a bool in Postgres, int (0/1) in SQLite
        $isArchived = $driver === 'pgsql'
            ? (bool) $row->todos_archivados
            : (int) ($row->todos_archivados ?? 0) === 1;

        return new EventoPepDTO(
            nombreNormalizado: (string) $row->nombre_normalizado,
            evento: isset($row->evento) ? (string) $row->evento : null,
            categoria: (string) ($row->categoria ?? 'PEP'),
            dia: $dia,
            numFuentes: (int) $row->num_fuentes,
            cargo: isset($row->cargo) ? (string) $row->cargo : null,
            resultadoIds: $resultadoIds,
            sitios: array_map('strval', $sitios), // site names will be resolved in view; store IDs here
            ultimaFechaEncontrado: $ultimaFechaEncontrado,
            isArchived: $isArchived,
        );
    }

    /**
     * Parse an aggregate array field to a PHP int[].
     *
     * PostgreSQL ARRAY_AGG returns a string like "{1,2,3}" (after PDO cast).
     * SQLite GROUP_CONCAT returns "1,2,3".
     */
    private function parseIntArray(string|null $value, string $driver): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $str = (string) $value;

        if ($driver === 'pgsql') {
            // Postgres: "{1,2,3}" → strip braces → split
            $str = trim($str, '{}');
        }

        if ($str === '') {
            return [];
        }

        return array_map('intval', explode(',', $str));
    }
}
