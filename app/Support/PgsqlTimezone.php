<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wraps unzoned timestamp columns with AT TIME ZONE config('app.timezone')
 * for use in pgsql raw SQL that compares/subtracts them against NOW()
 * (which is timestamptz). Renders the cluster's session_tz setting irrelevant.
 *
 * Canonical use: any pgsql `NOW() - <column>` or `<column> >= NOW() - INTERVAL`
 * pattern where <column> is a `timestamp without time zone` column.
 *
 * Caller responsibility: ensure surrounding query is pgsql (DB::getDriverName()
 * === 'pgsql'). This class is intentionally pgsql-only — see SDD
 * sql-portability-cleanup-dashboard Decision 3.
 *
 * Input contract: $column MUST be a simple identifier (`'fecha'`) or
 * table-qualified identifier (`'cambios.fecha'`). Expressions with operators
 * or function calls are NOT supported.
 */
final class PgsqlTimezone
{
    /**
     * Returns the SQL fragment "(column AT TIME ZONE 'app_tz')" for safe
     * interpolation into pgsql raw SQL.
     *
     * @throws \InvalidArgumentException when config('app.timezone') is not a
     *                                   recognised IANA identifier per timezone_identifiers_list(). The
     *                                   message references both the invalid value and the config key
     *                                   (app.timezone) for actionable diagnostics.
     */
    public static function normalize(string $column): string
    {
        /** @var string $tz */
        $tz = config('app.timezone', '');

        if (! in_array($tz, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException(
                "Invalid timezone \"{$tz}\" (config key: app.timezone). "
                .'Must be a valid IANA timezone identifier.'
            );
        }

        return sprintf("(%s AT TIME ZONE '%s')", $column, $tz);
    }
}
