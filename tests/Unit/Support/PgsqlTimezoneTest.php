<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PgsqlTimezone;
use Tests\TestCase;

/**
 * Unit tests for App\Support\PgsqlTimezone::normalize().
 *
 * RED → GREEN → REFACTOR per TDD strict mode.
 * Tests: T-U1, T-U2, T-U3 (Phase 1 — PgsqlTimezone helper)
 *
 * No DB connection required — pure PHP logic.
 */
class PgsqlTimezoneTest extends TestCase
{
    // =========================================================================
    // T-U1 — Valid IANA timezone returns correct SQL fragment
    // =========================================================================

    public function test_normalize_valid_iana_tz_returns_correct_sql_fragment(): void
    {
        config(['app.timezone' => 'America/La_Paz']);

        $result = PgsqlTimezone::normalize('fecha');

        $this->assertSame("(fecha AT TIME ZONE 'America/La_Paz')", $result);
    }

    // =========================================================================
    // T-U2 — UTC is accepted as a valid IANA identifier (no exception thrown)
    // =========================================================================

    public function test_normalize_utc_is_accepted_and_does_not_throw(): void
    {
        config(['app.timezone' => 'UTC']);

        $result = PgsqlTimezone::normalize('fecha');

        $this->assertSame("(fecha AT TIME ZONE 'UTC')", $result);
    }

    // =========================================================================
    // T-U3 — Invalid timezone throws InvalidArgumentException
    // =========================================================================

    public function test_normalize_invalid_tz_throws_invalid_argument_exception(): void
    {
        config(['app.timezone' => 'Invalid/Zone']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid\/Zone/');

        PgsqlTimezone::normalize('fecha');
    }
}
