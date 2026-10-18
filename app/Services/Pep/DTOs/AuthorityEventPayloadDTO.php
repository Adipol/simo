<?php

declare(strict_types=1);

namespace App\Services\Pep\DTOs;

use App\Exceptions\Pep\InvalidAuthorityEventPayload;

final readonly class AuthorityEventPayloadDTO
{
    /** @param list<AuthorityEventDTO> $events */
    public function __construct(
        public int $version,
        public array $events,
    ) {}

    public static function fromArray(array $data): self
    {
        $version = $data['version'] ?? null;
        if (! is_int($version) || $version !== 1) {
            throw new InvalidAuthorityEventPayload('Authority event payload version must be the exact integer 1.');
        }

        $rawEvents = $data['events'] ?? null;
        if (! is_array($rawEvents) || ! array_is_list($rawEvents) || $rawEvents === []) {
            throw new InvalidAuthorityEventPayload('Authority event payload must contain a non-empty event list.');
        }

        $events = [];
        foreach ($rawEvents as $rawEvent) {
            if (! is_array($rawEvent)) {
                throw new InvalidAuthorityEventPayload('Every authority event must be an object.');
            }

            $events[] = AuthorityEventDTO::fromArray($rawEvent);
        }

        return new self(version: $version, events: $events);
    }

    /** @return array{version:int,events:list<array{type:string,old:?array{cargo:string,persona:string},new:?array{cargo:string,persona:string}}>} */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'events' => array_map(
                static fn (AuthorityEventDTO $event): array => $event->toArray(),
                $this->events,
            ),
        ];
    }
}
