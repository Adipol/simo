<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

final class HeatmapPalette
{
    /** @var array<string> */
    public const LATAM_ISO_CODES = ['BO', 'AR', 'CL', 'PE', 'PY', 'BR', 'UY', 'EC', 'CO', 'VE'];

    private const BUCKET_COLORS = [
        'bg-rose-100',
        'bg-rose-200',
        'bg-rose-300',
        'bg-rose-400',
        'bg-rose-500',
    ];

    private const NONE_COLOR = 'bg-gray-100';

    /**
     * Return a Tailwind color class for a quintile bucket.
     *
     * Quintile boundaries (inclusive upper bound):
     *  Q1: 0 < pct ≤ 20% → bg-rose-100
     *  Q2: 20% < pct ≤ 40% → bg-rose-200
     *  Q3: 40% < pct ≤ 60% → bg-rose-300
     *  Q4: 60% < pct ≤ 80% → bg-rose-400
     *  Q5: 80% < pct ≤ 100% → bg-rose-500
     *  Zero or no data → bg-gray-100
     */
    public static function bucketColor(int $count, int $max): string
    {
        if ($count <= 0 || $max <= 0) {
            return self::NONE_COLOR;
        }

        $percentage = ($count / $max) * 100;

        $bucketIndex = (int) ceil($percentage / 20) - 1;
        $bucketIndex = max(0, min(4, $bucketIndex));

        return self::BUCKET_COLORS[$bucketIndex];
    }
}
