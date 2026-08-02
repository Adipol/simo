<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Extracts estado literals from PEPMonitor.procesar_fuente(), whose finally
 * block is the only assignment scope persisted to log_fuente_runs.
 *
 * ─── LIMITATIONS (REQ-A4) ───────────────────────────────────────────────────
 * Literal-only detection: this parser reads ONLY string literals assigned to
 * the identifier `estado` (e.g. `estado = "success"`). Any estado value that
 * is computed at runtime (e.g. `estado = get_estado()`) will NOT be detected.
 * This is intentional: the Python scraper historically uses literal assignments
 * for all `log_fuente_runs` estados, and this constraint is documented as a
 * known limitation of Contract A.
 *
 * Scoping extraction to this method excludes authority-review SQL statuses and
 * log_scripts statuses without maintaining an unrelated-value allowlist.
 */
final class PepMonitorParser
{
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
        preg_match(
            '/^    def procesar_fuente\(.*?(?=^    def check_all\()/ms',
            $content,
            $methodMatch,
        );
        $method = $methodMatch[0] ?? '';

        // Matches: estado = "value" or estado = 'value' (with optional spaces)
        // Captures only lowercase letters and underscores (valid estado shape).
        preg_match_all('/estado\s*=\s*["\']([a-z_]+)["\']/m', $method, $matches);

        $literals = $matches[1] ?? [];
        $unique = array_unique($literals);

        if (count($unique) === 0) {
            throw new \RuntimeException(
                'PepMonitorParser found zero estado literals after filtering. '
                .'The method boundary or literal regex may need updating.'
            );
        }

        sort($unique);

        return $unique;
    }
}
