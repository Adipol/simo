<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class SourceHealthDTO
{
    /** @var array<string> */
    public const VALID_STATUSES = ['ok', 'degradado', 'muerto', 'sin_info'];

    public function __construct(
        public int $fuente_id,
        public string $nombre,
        public string $status,
        public int $consecutive_failures,
        public ?\DateTimeImmutable $last_run_at,
        public ?\DateTimeImmutable $last_ok_at,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('fuente_id', $data)) {
            throw new \InvalidArgumentException('Missing required field: fuente_id');
        }

        if (! array_key_exists('nombre', $data)) {
            throw new \InvalidArgumentException('Missing required field: nombre');
        }

        if (! array_key_exists('status', $data)) {
            throw new \InvalidArgumentException('Missing required field: status');
        }

        $status = (string) $data['status'];

        if (! in_array($status, self::VALID_STATUSES, strict: true)) {
            throw new \InvalidArgumentException(
                "Invalid status value: '{$status}'. Must be one of: ".implode(', ', self::VALID_STATUSES)
            );
        }

        return new self(
            fuente_id: (int) $data['fuente_id'],
            nombre: (string) $data['nombre'],
            status: $status,
            consecutive_failures: (int) ($data['consecutive_failures'] ?? 0),
            last_run_at: $data['last_run_at'] instanceof \DateTimeImmutable ? $data['last_run_at'] : null,
            last_ok_at: $data['last_ok_at'] instanceof \DateTimeImmutable ? $data['last_ok_at'] : null,
        );
    }
}
