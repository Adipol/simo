<?php

declare(strict_types=1);

namespace App\Services\Pep\DTOs;

use App\Exceptions\Pep\InvalidAuthorityEventPayload;

final readonly class AuthorityDTO
{
    public function __construct(
        public string $cargo,
        public string $persona,
    ) {}

    public static function fromArray(array $data): self
    {
        foreach (['cargo', 'persona'] as $field) {
            $value = $data[$field] ?? null;
            if (! is_string($value) || ! self::hasNonWhitespaceCharacter($value)) {
                throw new InvalidAuthorityEventPayload("Authority field '{$field}' must contain non-whitespace text.");
            }
        }

        return new self(
            cargo: $data['cargo'],
            persona: $data['persona'],
        );
    }

    /** @return array{cargo:string,persona:string} */
    public function toArray(): array
    {
        return [
            'cargo' => $this->cargo,
            'persona' => $this->persona,
        ];
    }

    private static function hasNonWhitespaceCharacter(string $value): bool
    {
        return preg_match('/[^\p{Z}\x{0009}-\x{000D}\x{0085}]/u', $value) === 1;
    }
}
