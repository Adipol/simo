<?php

declare(strict_types=1);

namespace Tests\Support;

use JsonException;

/** Pure exact matching for owned fixtures only; no connection or application. */
final class PepDirectorySchemaContract
{
    // Complete approved structure, not merely decodable JSON or table counts.
    private const CONTRACT_SHA256 = '48d59444f9d9a045ad1293558a8bbd12f3d111c10fd79945b3e41806b4d901a7';

    /** JSON values only; the union deliberately excludes executable objects. */
    public static function validate(array|string|int|float|bool|null $snapshot): string
    {
        if (func_num_args() !== 1) {
            return 'schema_mismatch';
        }
        $path = dirname(__DIR__).'/Fixtures/PepDirectory/schema-contracts/contract.json';
        if (!is_readable($path)) {
            return 'contract_unavailable';
        }
        try {
            $raw = file_get_contents($path);
            if ($raw === false) {
                return 'contract_unavailable';
            }
            $contract = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'contract_unavailable';
        }
        if (!is_array($contract) || count($contract) !== 3
            || !isset($contract['format'], $contract['v1'], $contract['v2_design'])) {
            return 'contract_unavailable';
        }
        foreach (['v1' => ['v1', 6], 'v2_design' => ['v2', 10]] as $key => [$version, $count]) {
            $section = $contract[$key];
            if (!is_array($section) || count($section) !== 6
                || !isset($section['version'], $section['schema'], $section['owner'], $section['tables'], $section['triggers'], $section['functions'])
                || $section['version'] !== $version || !is_array($section['tables']) || count($section['tables']) !== $count
                || !is_array($section['triggers']) || !is_array($section['functions'])) {
                return 'contract_unavailable';
            }
        }
        // Pin nested completeness too: malformed expected == malformed input is unsafe.
        if (hash('sha256', $raw) !== self::CONTRACT_SHA256) {
            return 'contract_unavailable';
        }
        if (self::matches($contract['v1'], $snapshot)) {
            return 'v1';
        }
        if (self::matches($contract['v2_design'], $snapshot)) {
            return 'unattested_v2'; // Not an installed-schema success.
        }

        return 'schema_mismatch';
    }

    private static function matches(array|string|int|float|bool|null $expected, array|string|int|float|bool|null $actual): bool
    {
        if (get_debug_type($expected) !== get_debug_type($actual)) {
            return false;
        }
        if (!is_array($expected)) {
            return $expected === $actual;
        }
        if (count($expected) !== count($actual) || array_is_list($expected) !== array_is_list($actual)) {
            return false;
        }
        // Walk only the fixed expected tree, never unknown keys or recursive input.
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !self::matches($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }
}
