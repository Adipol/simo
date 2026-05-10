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
     * Hex fill values for SVG rendering, keyed by Tailwind class name.
     * Centralised here so Blade views never resolve hex values themselves.
     */
    private const BUCKET_HEX = [
        'bg-rose-100' => '#ffe4e6',
        'bg-rose-200' => '#fecdd3',
        'bg-rose-300' => '#fda4af',
        'bg-rose-400' => '#fb7185',
        'bg-rose-500' => '#f43f5e',
        'bg-gray-100' => '#f3f4f6',
    ];

    /**
     * Simplified bounding-box data for 10 LATAM countries.
     * Each entry: [x, y, w, h, lx (label), ly (label), name].
     * Coordinate system: 0–220 wide, 0–240 tall (approximate LATAM bounding box).
     *
     * DEVIATION: Full SVG paths replaced with bounding-box rectangles for
     * maintainability. Visual fidelity can be improved in a future iteration.
     *
     * @return array<string, array{x:int, y:int, w:int, h:int, lx:int, ly:int, name:string}>
     */
    public static function latamCountries(): array
    {
        return [
            'VE' => ['x' => 70,  'y' => 10,  'w' => 50,  'h' => 30,  'lx' => 95,  'ly' => 25,  'name' => 'Venezuela'],
            'CO' => ['x' => 50,  'y' => 40,  'w' => 50,  'h' => 40,  'lx' => 75,  'ly' => 60,  'name' => 'Colombia'],
            'EC' => ['x' => 40,  'y' => 80,  'w' => 30,  'h' => 30,  'lx' => 55,  'ly' => 95,  'name' => 'Ecuador'],
            'PE' => ['x' => 45,  'y' => 110, 'w' => 40,  'h' => 50,  'lx' => 65,  'ly' => 135, 'name' => 'Perú'],
            'BR' => ['x' => 100, 'y' => 50,  'w' => 110, 'h' => 100, 'lx' => 155, 'ly' => 100, 'name' => 'Brasil'],
            'BO' => ['x' => 80,  'y' => 130, 'w' => 45,  'h' => 45,  'lx' => 103, 'ly' => 153, 'name' => 'Bolivia'],
            'PY' => ['x' => 110, 'y' => 155, 'w' => 35,  'h' => 30,  'lx' => 128, 'ly' => 170, 'name' => 'Paraguay'],
            'CL' => ['x' => 50,  'y' => 160, 'w' => 25,  'h' => 75,  'lx' => 63,  'ly' => 198, 'name' => 'Chile'],
            'AR' => ['x' => 75,  'y' => 165, 'w' => 55,  'h' => 70,  'lx' => 103, 'ly' => 200, 'name' => 'Argentina'],
            'UY' => ['x' => 130, 'y' => 188, 'w' => 25,  'h' => 22,  'lx' => 143, 'ly' => 199, 'name' => 'Uruguay'],
        ];
    }

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

    /**
     * Return the hex fill color for SVG rendering.
     * Resolves the Tailwind class from bucketColor() to an inline hex value,
     * since SVG fill attributes cannot use Tailwind class names directly.
     */
    public static function bucketHex(int $count, int $max): string
    {
        $class = self::bucketColor($count, $max);

        return self::BUCKET_HEX[$class] ?? self::BUCKET_HEX[self::NONE_COLOR];
    }
}
