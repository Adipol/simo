<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Static helper that extracts the unique `estado` string literals emitted by
 * pep_monitor.py for the `log_fuente_runs` table.
 *
 * ─── LIMITATIONS (REQ-A4) ───────────────────────────────────────────────────
 * Literal-only detection: this parser reads ONLY string literals assigned to
 * the identifier `estado` (e.g. `estado = "success"`). Any estado value that
 * is computed at runtime (e.g. `estado = get_estado()`) will NOT be detected.
 * This is intentional: the Python scraper historically uses literal assignments
 * for all `log_fuente_runs` estados, and this constraint is documented as a
 * known limitation of Contract A.
 *
 * ─── LOG_SCRIPTS ALLOWLIST ──────────────────────────────────────────────────
 * pep_monitor.py also emits `estado` literals for the `log_scripts` table via
 * `self.db.log_fin(...)` calls (lines 1696 "completado", 1705 "error").
 * These must be excluded because they belong to a different table and would
 * produce false drift alarms in Contract A. The allowlist is hardcoded and
 * documented here rather than configurable, because:
 * (a) these values are stable constants in the Python code,
 * (b) making them configurable would couple the test infrastructure to runtime
 *     config, defeating the purpose of a hermetic contract test.
 */
final class PepMonitorParser
{
    /**
     * Estado values used exclusively by the `log_scripts` table.
     * Excluded from the returned set to avoid false drift alarms in Contract A.
     *
     * @var array<string>
     */
    private const LOG_SCRIPTS_ALLOWLIST = ['completado', 'error'];

    /**
     * Parse pep_monitor.py and return the unique, sorted list of `estado`
     * string literals assigned for `log_fuente_runs`.
     *
     * @return array<string> Sorted unique estado values.
     *
     * @throws \RuntimeException If the Python file is missing or the regex
     *                           yields zero literals after filtering (which
     *                           would mean the file was moved or the literal
     *                           patterns changed — a signal to update this parser).
     */
    public static function emittedEstados(): array
    {
        $path = base_path('scripts/website_monitor_pro/pep_monitor.py');

        if (! file_exists($path)) {
            throw new \RuntimeException(
                "pep_monitor.py not found at expected path: {$path}. "
                .'Update PepMonitorParser::$path if the file was moved.'
            );
        }

        $content = (string) file_get_contents($path);

        // Matches: estado = "value" or estado = 'value' (with optional spaces)
        // Captures only lowercase letters and underscores (valid estado shape).
        preg_match_all('/estado\s*=\s*["\']([a-z_]+)["\']/m', $content, $matches);

        $literals = $matches[1] ?? [];

        // Filter out log_scripts estados to keep only log_fuente_runs values.
        $filtered = array_filter(
            $literals,
            static fn (string $v): bool => ! in_array($v, self::LOG_SCRIPTS_ALLOWLIST, true)
        );

        $unique = array_unique(array_values($filtered));

        if (count($unique) === 0) {
            throw new \RuntimeException(
                'PepMonitorParser found zero estado literals after filtering. '
                .'The regex pattern or the log_scripts allowlist may need updating.'
            );
        }

        sort($unique);

        return $unique;
    }
}
