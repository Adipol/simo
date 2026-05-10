<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class PepHighConfidence
{
    public function __construct(
        public int $id,
        public string $nombre,
        public ?string $cargo,
        public ?string $pais,
        public float $confianza,
        public string $categoria,
        public \DateTimeImmutable $fecha,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            nombre: (string) $data['nombre'],
            cargo: isset($data['cargo']) ? (string) $data['cargo'] : null,
            pais: isset($data['pais']) ? (string) $data['pais'] : null,
            confianza: (float) $data['confianza'],
            categoria: (string) $data['categoria'],
            fecha: new \DateTimeImmutable((string) $data['fecha']),
        );
    }

    /**
     * Return up to 2 uppercase initials from the person's name.
     * E.g. "Juan Pérez" → "JP", "Ministerio" → "M", "" → "?".
     */
    public function initials(): string
    {
        $parts  = array_filter(explode(' ', $this->nombre));
        $result = implode('', array_map(
            fn (string $p): string => strtoupper(substr($p, 0, 1)),
            array_slice(array_values($parts), 0, 2),
        ));

        return $result ?: '?';
    }

    /**
     * Return a Tailwind bg class for the avatar based on confidence level.
     * ≥ 95% → emerald, ≥ 85% → indigo, ≥ 75% → amber, else → zinc.
     */
    public function avatarColorClass(): string
    {
        return match(true) {
            $this->confianza >= 95 => 'bg-emerald-500',
            $this->confianza >= 85 => 'bg-indigo-500',
            $this->confianza >= 75 => 'bg-amber-500',
            default                => 'bg-zinc-400',
        };
    }

    /**
     * Return a Tailwind bg class for the confidence bar fill.
     * Mirrors avatarColorClass — same confidence → same semantic color.
     */
    public function confidenceBarColorClass(): string
    {
        return $this->avatarColorClass();
    }

    /**
     * Return the confidence value clamped to [0, 100].
     * Defensive guard in case the service produces an out-of-range value.
     */
    public function clampedConfianza(): float
    {
        return min(100.0, max(0.0, $this->confianza));
    }

    /**
     * Return the time component of fecha formatted as "HH:MM".
     */
    public function formattedTime(): string
    {
        return \Carbon\Carbon::instance($this->fecha)->format('H:i');
    }
}
