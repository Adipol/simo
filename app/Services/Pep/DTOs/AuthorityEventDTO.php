<?php

declare(strict_types=1);

namespace App\Services\Pep\DTOs;

use App\Exceptions\Pep\InvalidAuthorityEventPayload;

final readonly class AuthorityEventDTO
{
    /** @var list<string> */
    private const EVENT_TYPES = [
        'designacion',
        'remocion',
        'reemplazo',
        'cambio_cargo',
    ];

    public function __construct(
        public string $type,
        public ?AuthorityDTO $old,
        public ?AuthorityDTO $new,
    ) {}

    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::EVENT_TYPES, true)) {
            throw new InvalidAuthorityEventPayload('Authority event type is not canonical.');
        }

        return match ($type) {
            'designacion' => new self(
                type: $type,
                old: self::nullAuthority($data, 'old'),
                new: self::requiredAuthority($data, 'new'),
            ),
            'remocion' => new self(
                type: $type,
                old: self::requiredAuthority($data, 'old'),
                new: self::nullAuthority($data, 'new'),
            ),
            'reemplazo', 'cambio_cargo' => new self(
                type: $type,
                old: self::requiredAuthority($data, 'old'),
                new: self::requiredAuthority($data, 'new'),
            ),
        };
    }

    /** @return array{type:string,old:?array{cargo:string,persona:string},new:?array{cargo:string,persona:string}} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'old' => $this->old?->toArray(),
            'new' => $this->new?->toArray(),
        ];
    }

    private static function requiredAuthority(array $data, string $key): AuthorityDTO
    {
        $authority = $data[$key] ?? null;
        if (! is_array($authority)) {
            throw new InvalidAuthorityEventPayload("Authority event field '{$key}' must be an authority object.");
        }

        return AuthorityDTO::fromArray($authority);
    }

    private static function nullAuthority(array $data, string $key): ?AuthorityDTO
    {
        if (! array_key_exists($key, $data) || $data[$key] !== null) {
            throw new InvalidAuthorityEventPayload("Authority event field '{$key}' must be null.");
        }

        return null;
    }
}
