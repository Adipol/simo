<?php

declare(strict_types=1);

namespace App\Services\Pep\DTOs;

use Carbon\CarbonImmutable;

/**
 * Represents a deduplicated "PEP event" group from the panel query.
 *
 * One DTO = one (nombre_normalizado, evento, día) triplet aggregated
 * across all matching resultado_personas / resultados_scraping rows.
 *
 * This is a value object: readonly, constructed from DB aggregate rows.
 */
final readonly class EventoPepDTO
{
    /**
     * @param  string          $nombreNormalizado  Normalized person name (GROUP BY key).
     * @param  string|null     $evento             Event type (renuncia, designacion, crimen…) or null = "Sin clasificar".
     * @param  string          $categoria          "PEP" or "OPI".
     * @param  CarbonImmutable $dia                The date of the event (DATE(fecha_encontrado)).
     * @param  int             $numFuentes         Count of distinct source rows in this group.
     * @param  string|null     $cargo              Most recent non-null cargo from the group.
     * @param  int[]           $resultadoIds       Snapshot of resultados_scraping IDs for archiving.
     * @param  string[]        $sitios             List of site names for display.
     * @param  CarbonImmutable $ultimaFechaEncontrado  Latest fecha_encontrado in the group.
     * @param  bool            $isArchived         True when all resultados_scraping in the group are archived.
     */
    public function __construct(
        public string $nombreNormalizado,
        public ?string $evento,
        public string $categoria,
        public CarbonImmutable $dia,
        public int $numFuentes,
        public ?string $cargo,
        public array $resultadoIds,
        public array $sitios,
        public CarbonImmutable $ultimaFechaEncontrado,
        public bool $isArchived,
    ) {}

    /**
     * Stable key for wire:key and group identification.
     *
     * Uses the (nombre_normalizado, evento, día) triplet — the same GROUP BY keys
     * used in the query — so two independently fetched groups for the same event
     * will always produce the same key.
     *
     * null evento is encoded as the literal string "\0" (a single NUL byte)
     * to distinguish it from an empty string evento, preventing key collisions.
     */
    public function key(): string
    {
        // Use NUL byte as sentinel for null to distinguish from empty string
        $eventoKey = $this->evento ?? "\0";

        return md5("{$this->nombreNormalizado}|{$eventoKey}|{$this->dia->toDateString()}");
    }
}
