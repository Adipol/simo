<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class SourceHealthSummaryDTO
{
    public function __construct(
        public int $total_fuentes_activas,
        public int $ok,
        public int $degradadas,
        public int $muertas,
        public int $sin_info,
        public bool $available,
        public \DateTimeImmutable $last_aggregation_at,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('total_fuentes_activas', $data)) {
            throw new \InvalidArgumentException('Missing required field: total_fuentes_activas');
        }

        if (! array_key_exists('last_aggregation_at', $data)) {
            throw new \InvalidArgumentException('Missing required field: last_aggregation_at');
        }

        $total = (int) $data['total_fuentes_activas'];
        $ok = (int) ($data['ok'] ?? 0);
        $degradadas = (int) ($data['degradadas'] ?? 0);
        $muertas = (int) ($data['muertas'] ?? 0);
        $sinInfo = (int) ($data['sin_info'] ?? 0);

        // Invariant: ok + degradadas + muertas + sin_info === total_fuentes_activas
        if ($total !== ($ok + $degradadas + $muertas + $sinInfo)) {
            throw new \InvalidArgumentException(
                "DTO count invariant violated: ok({$ok}) + degradadas({$degradadas}) + muertas({$muertas}) + sin_info({$sinInfo}) !== total({$total})"
            );
        }

        $lastAggregationAt = $data['last_aggregation_at'];
        if (! $lastAggregationAt instanceof \DateTimeImmutable) {
            $lastAggregationAt = new \DateTimeImmutable((string) $lastAggregationAt);
        }

        return new self(
            total_fuentes_activas: $total,
            ok: $ok,
            degradadas: $degradadas,
            muertas: $muertas,
            sin_info: $sinInfo,
            available: (bool) ($data['available'] ?? ($total > 0)),
            last_aggregation_at: $lastAggregationAt,
        );
    }

    /**
     * Returns an "unavailable" summary when no active fuentes exist.
     */
    public static function unavailable(): self
    {
        return new self(
            total_fuentes_activas: 0,
            ok: 0,
            degradadas: 0,
            muertas: 0,
            sin_info: 0,
            available: false,
            last_aggregation_at: new \DateTimeImmutable,
        );
    }
}
