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

    /**
     * Derived pill status for the health-strip component.
     *
     * Possible values: 'error' | 'warning' | 'ok' | 'no_data'
     * - 'error'   = any muertas
     * - 'warning' = any degradadas (no muertas)
     * - 'ok'      = all ok or mixed ok+sin_info (positive signal)
     * - 'no_data' = unavailable OR all sin_info (warmup)
     */
    public function pillStatus(): string
    {
        if (! $this->available) {
            return 'no_data';
        }

        if ($this->muertas > 0) {
            return 'error';
        }

        if ($this->degradadas > 0) {
            return 'warning';
        }

        if ($this->ok === 0 && $this->sin_info > 0) {
            return 'no_data';
        }

        return 'ok';
    }

    /**
     * Derived pill display text for the health-strip component.
     *
     * Returns null when the pill should use its own "collecting" sub-template.
     */
    public function pillText(): ?string
    {
        if (! $this->available) {
            return null; // template uses "Sin fuentes activas" sub-render
        }

        if ($this->ok === 0 && $this->sin_info > 0 && $this->degradadas === 0 && $this->muertas === 0) {
            return null; // template uses "Recolectando datos…" sub-render
        }

        $parts = [];

        if ($this->ok > 0) {
            $parts[] = $this->ok.' ok';
        }

        if ($this->degradadas > 0) {
            $parts[] = $this->degradadas.' degradada'.($this->degradadas > 1 ? 's' : '');
        }

        if ($this->muertas > 0) {
            $parts[] = $this->muertas.' muerta'.($this->muertas > 1 ? 's' : '');
        }

        if ($this->sin_info > 0) {
            $parts[] = $this->sin_info.' sin datos';
        }

        return implode(' / ', $parts);
    }

    /**
     * Warmup state: active fuentes exist but none have run data yet.
     */
    public function isWarmup(): bool
    {
        return $this->available && $this->ok === 0 && $this->degradadas === 0 && $this->muertas === 0 && $this->sin_info > 0;
    }
}
