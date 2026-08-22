<?php

declare(strict_types=1);

namespace App\Services\Scraper\DTOs;

final readonly class SiteValidationResult
{
    public function __construct(
        public bool $valid,
        public string $diagnostic,
        public ?string $resolvedUrl = null,
        public int $articleCandidates = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            valid: (bool) $data['valid'],
            diagnostic: (string) $data['diagnostic'],
            resolvedUrl: isset($data['resolved_url']) ? (string) $data['resolved_url'] : null,
            articleCandidates: (int) ($data['article_candidates'] ?? 0),
        );
    }
}
