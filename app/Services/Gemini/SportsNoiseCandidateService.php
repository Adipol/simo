<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\SportsNoiseDecisionDTO;

final class SportsNoiseCandidateService
{
    private const RULE_VERSION = 'sports-noise-v1';

    private const MAX_CODES = 3;

    public function decide(ResultadoScraping $record): SportsNoiseDecisionDTO
    {
        $configuration = config('services.sports_noise');

        if (! $this->isValidConfiguration($configuration)) {
            return $this->passOpen($record, ['invalid_configuration']);
        }

        if ($configuration['enabled'] !== true) {
            return $this->passOpen($record, ['disabled']);
        }

        $text = $this->normalizedText($record);

        if ($text === '') {
            return $this->passOpen($record, ['incomplete_evidence']);
        }

        $escapeCodes = $this->matchingCodes($text, $configuration['catalog']['escape_terms']);

        if ($escapeCodes !== []) {
            return $this->passOpen($record, $escapeCodes);
        }

        $catalog = $configuration['catalog'];
        $reasonCodes = [];

        if ($this->matchesAny($text, $catalog['club_terms'])) {
            $reasonCodes[] = 'club_private';
        }

        if ($this->matchesAny($text, $catalog['role_terms'])) {
            $reasonCodes[] = 'role_coach';
        }

        if ($this->matchesAny($text, $catalog['status_terms'])) {
            $reasonCodes[] = 'status_appointed';
        }

        if (count($reasonCodes) !== self::MAX_CODES) {
            return $this->passOpen($record, ['incomplete_evidence']);
        }

        sort($reasonCodes);

        return new SportsNoiseDecisionDTO(
            outcome: 'candidate',
            ruleVersion: self::RULE_VERSION,
            reasonCodes: $reasonCodes,
            escapeCodes: [],
            titleFingerprint: $this->fingerprint($record),
            excerpt: null,
        );
    }

    /** @param array<int, string> $escapeCodes */
    private function passOpen(ResultadoScraping $record, array $escapeCodes): SportsNoiseDecisionDTO
    {
        sort($escapeCodes);

        return new SportsNoiseDecisionDTO(
            outcome: 'pass_open',
            ruleVersion: self::RULE_VERSION,
            reasonCodes: [],
            escapeCodes: array_slice($escapeCodes, 0, self::MAX_CODES),
            titleFingerprint: $this->fingerprint($record),
            excerpt: null,
        );
    }

    private function normalizedText(ResultadoScraping $record): string
    {
        return mb_strtolower(trim(($record->titulo ?? '').' '.($record->contexto ?? '')));
    }

    private function fingerprint(ResultadoScraping $record): ?string
    {
        $title = trim((string) ($record->titulo ?? ''));

        return $title === '' ? null : hash('sha256', $title);
    }

    /** @param array<string, array<int, string>> $codes */
    private function matchingCodes(string $text, array $codes): array
    {
        $matchingCodes = [];

        foreach ($codes as $code => $terms) {
            if ($this->matchesAny($text, $terms)) {
                $matchingCodes[] = $code;
            }
        }

        sort($matchingCodes);

        return array_slice($matchingCodes, 0, self::MAX_CODES);
    }

    /** @param array<int, string> $terms */
    private function matchesAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    /** External configuration may be malformed, so this boundary accepts mixed input. */
    private function isValidConfiguration(mixed $configuration): bool
    {
        if (! is_array($configuration) || ! is_bool($configuration['enabled'] ?? null)) {
            return false;
        }

        $catalog = $configuration['catalog'] ?? null;

        if (! is_array($catalog)) {
            return false;
        }

        foreach (['club_terms', 'role_terms', 'status_terms'] as $key) {
            if (! isset($catalog[$key]) || ! is_array($catalog[$key]) || ! $this->isStringList($catalog[$key])) {
                return false;
            }
        }

        if (! isset($catalog['escape_terms']) || ! is_array($catalog['escape_terms']) || $catalog['escape_terms'] === []) {
            return false;
        }

        foreach ($catalog['escape_terms'] as $code => $terms) {
            if (! is_string($code) || ! is_array($terms) || ! $this->isStringList($terms)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $terms */
    private function isStringList(array $terms): bool
    {
        if ($terms === []) {
            return false;
        }

        foreach ($terms as $term) {
            if (! is_string($term) || trim($term) === '') {
                return false;
            }
        }

        return true;
    }
}
