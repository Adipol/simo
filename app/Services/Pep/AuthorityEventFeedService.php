<?php

declare(strict_types=1);

namespace App\Services\Pep;

use App\Enums\CambioFeedStatus;
use App\Exceptions\Pep\InvalidAuthorityEventPayload;
use App\Services\Pep\DTOs\AuthorityEventPayloadDTO;

final class AuthorityEventFeedService
{
    public function classify(?array $payload, CambioFeedStatus $destination): CambioFeedStatus
    {
        if ($destination !== CambioFeedStatus::Primary) {
            return $destination;
        }

        return $this->isValidPayload($payload)
            ? CambioFeedStatus::Primary
            : CambioFeedStatus::Review;
    }

    public function assertPrimaryEligible(?array $payload): AuthorityEventPayloadDTO
    {
        if ($payload === null) {
            throw new InvalidAuthorityEventPayload('A primary feed row requires a valid canonical authority-event payload.');
        }

        return AuthorityEventPayloadDTO::fromArray($payload);
    }

    public function isValidPayload(?array $payload): bool
    {
        try {
            $this->assertPrimaryEligible($payload);
        } catch (InvalidAuthorityEventPayload) {
            return false;
        }

        return true;
    }
}
