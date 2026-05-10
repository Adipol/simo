<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Services\Dashboard\HeatmapPalette;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeatmapPaletteTest extends TestCase
{
    // ─── LATAM_ISO_CODES constant ─────────────────────────────────────────────

    public function test_latam_iso_codes_contains_10_countries(): void
    {
        $codes = HeatmapPalette::LATAM_ISO_CODES;

        $this->assertCount(10, $codes);
    }

    public function test_latam_iso_codes_contains_expected_countries(): void
    {
        $expected = ['BO', 'AR', 'CL', 'PE', 'PY', 'BR', 'UY', 'EC', 'CO', 'VE'];

        $this->assertSame($expected, HeatmapPalette::LATAM_ISO_CODES);
    }

    // ─── bucketColor — zero count ─────────────────────────────────────────────

    public function test_bucket_color_returns_grey_when_count_is_zero(): void
    {
        $color = HeatmapPalette::bucketColor(0, 100);

        $this->assertSame('bg-gray-100', $color);
    }

    public function test_bucket_color_returns_grey_when_max_is_zero(): void
    {
        // All-zero data: max=0 edge case
        $color = HeatmapPalette::bucketColor(0, 0);

        $this->assertSame('bg-gray-100', $color);
    }

    // ─── bucketColor — quintile buckets ──────────────────────────────────────

    #[DataProvider('quintileBucketProvider')]
    public function test_bucket_color_quintile_boundaries(int $count, int $max, string $expectedColor): void
    {
        $color = HeatmapPalette::bucketColor($count, $max);

        $this->assertSame($expectedColor, $color);
    }

    public static function quintileBucketProvider(): array
    {
        // max=100: quintiles at 20, 40, 60, 80, 100
        return [
            'Q1 — 20% (lowest non-zero)'  => [1,   100, 'bg-rose-100'],
            'Q1 boundary at 20'           => [20,  100, 'bg-rose-100'],
            'Q2 — just above 20%'         => [21,  100, 'bg-rose-200'],
            'Q2 boundary at 40'           => [40,  100, 'bg-rose-200'],
            'Q3 — just above 40%'         => [41,  100, 'bg-rose-300'],
            'Q3 boundary at 60'           => [60,  100, 'bg-rose-300'],
            'Q4 — just above 60%'         => [61,  100, 'bg-rose-400'],
            'Q4 boundary at 80'           => [80,  100, 'bg-rose-400'],
            'Q5 — just above 80%'         => [81,  100, 'bg-rose-500'],
            'Q5 — max value'              => [100, 100, 'bg-rose-500'],
        ];
    }

    // ─── Mid-bucket boundary ──────────────────────────────────────────────────

    public function test_bucket_color_mid_bucket_uses_correct_bucket(): void
    {
        // count=50, max=100 → 50% → Q3 bucket (41-60%)
        $color = HeatmapPalette::bucketColor(50, 100);

        $this->assertSame('bg-rose-300', $color);
    }

    public function test_bucket_color_works_with_small_max(): void
    {
        // count=3, max=5 → 60% → Q3 boundary
        $color = HeatmapPalette::bucketColor(3, 5);

        $this->assertSame('bg-rose-300', $color);
    }
}
