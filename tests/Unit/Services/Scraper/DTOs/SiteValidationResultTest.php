<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scraper\DTOs;

use App\Services\Scraper\DTOs\SiteValidationResult;
use Tests\TestCase;

class SiteValidationResultTest extends TestCase
{
    public function test_from_array_maps_all_fields(): void
    {
        $result = SiteValidationResult::fromArray([
            'valid' => true,
            'diagnostic' => 'Validation succeeded.',
            'resolved_url' => 'https://example.com/news',
            'article_candidates' => 12,
        ]);

        $this->assertTrue($result->valid);
        $this->assertSame('Validation succeeded.', $result->diagnostic);
        $this->assertSame('https://example.com/news', $result->resolvedUrl);
        $this->assertSame(12, $result->articleCandidates);
    }

    public function test_from_array_uses_constructor_defaults_for_optional_fields(): void
    {
        $result = SiteValidationResult::fromArray([
            'valid' => false,
            'diagnostic' => 'No article candidates found.',
        ]);

        $this->assertFalse($result->valid);
        $this->assertSame('No article candidates found.', $result->diagnostic);
        $this->assertNull($result->resolvedUrl);
        $this->assertSame(0, $result->articleCandidates);
    }
}
