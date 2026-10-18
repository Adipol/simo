<?php

declare(strict_types=1);

namespace App\Services\Pep;

use App\Enums\CambioFeedStatus;
use App\Exceptions\Pep\InvalidAuthorityEventPayload;
use App\Services\Gemini\DTOs\AnalisisCambioDTO;
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

    public function classifyAnalyzedCandidate(
        ?array $payload,
        CambioFeedStatus $destination,
        AnalisisCambioDTO $analysis,
    ): CambioFeedStatus {
        $admittedDestination = $this->classify($payload, $destination);

        if ($admittedDestination !== CambioFeedStatus::Review) {
            return $admittedDestination;
        }

        return $this->hasStructuredCandidateEvidence($payload)
            || $this->hasAnalysisCandidateEvidence($analysis)
                ? CambioFeedStatus::Review
                : CambioFeedStatus::Suppressed;
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

    private function hasStructuredCandidateEvidence(?array $payload): bool
    {
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            foreach (['old', 'new'] as $side) {
                $authority = $event[$side] ?? null;

                if (! is_array($authority)) {
                    continue;
                }

                $persona = $authority['persona'] ?? null;
                $cargo = $authority['cargo'] ?? null;

                if ((is_string($persona) && $this->hasUsableText($persona))
                    || (is_string($cargo) && $this->hasUsableText($cargo))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasAnalysisCandidateEvidence(AnalisisCambioDTO $analysis): bool
    {
        if ($this->hasUsableText($analysis->personaRemovida)
            || $this->hasUsableText($analysis->personaNueva)
            || $this->hasUsableText($analysis->cargo)) {
            return true;
        }

        foreach ($analysis->personasDetectadas as $person) {
            if ($this->hasUsableText($person['nombre'] ?? null)
                || $this->hasUsableText($person['cargo'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasUsableText(?string $value): bool
    {
        return $value !== null
            && preg_match('/[^\p{Z}\x{0009}-\x{000D}\x{0085}]/u', $value) === 1;
    }
}
