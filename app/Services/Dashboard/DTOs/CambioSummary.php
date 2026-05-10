<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class CambioSummary
{
    public function __construct(
        public int $id,
        public string $fuente_nombre,
        public string $riesgo,
        public int $lineas_nuevas,
        public int $lineas_quitadas,
        public ?string $analisis_snippet,
        public \DateTimeImmutable $fecha,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            fuente_nombre: (string) $data['fuente_nombre'],
            riesgo: (string) $data['riesgo'],
            lineas_nuevas: (int) ($data['lineas_nuevas'] ?? 0),
            lineas_quitadas: (int) ($data['lineas_quitadas'] ?? 0),
            analisis_snippet: isset($data['analisis_snippet']) ? (string) $data['analisis_snippet'] : null,
            fecha: new \DateTimeImmutable((string) $data['fecha']),
        );
    }

    /**
     * Return the Tailwind badge class string for this cambio's riesgo level.
     */
    public function riskBadgeClass(): string
    {
        return match($this->riesgo) {
            'alto'  => 'bg-rose-50 text-rose-600',
            'medio' => 'bg-amber-50 text-amber-600',
            'bajo'  => 'bg-emerald-50 text-emerald-600',
            default => 'bg-zinc-100 text-zinc-500',
        };
    }

    /**
     * Return a human-readable relative time string (e.g. "hace 2 horas").
     */
    public function diffForHumans(): string
    {
        return \Carbon\Carbon::instance($this->fecha)->diffForHumans();
    }
}
