<?php

declare(strict_types=1);

namespace Tests\Feature\Dedupe;

use Tests\TestCase;

/**
 * Regression guard for SCN-5.1: services.dedupe.enabled defaults to true.
 *
 * This test does NOT drive new behavior — it locks in the existing default
 * defined at config/services.php:38-40 ('enabled' => env('DEDUPE_ENABLED', true)).
 * If someone ever changes that default, this test fails loudly so the change
 * is intentional, not accidental.
 *
 * The artificial RED step is skipped because the config is already correct;
 * this is a coverage-gap closure (SCN-5.1) flagged by the verify-report (#868),
 * not a new-feature TDD cycle.
 */
final class DedupeConfigDefaultTest extends TestCase
{
    public function test_dedupe_enabled_defaults_to_true_when_env_var_not_set(): void
    {
        // Clear any DEDUPE_ENABLED env override that might have leaked from the test env
        putenv('DEDUPE_ENABLED');
        unset($_ENV['DEDUPE_ENABLED'], $_SERVER['DEDUPE_ENABLED']);

        // Re-read the config file directly (bypasses container cache)
        $config = require base_path('config/services.php');

        $this->assertTrue(
            $config['dedupe']['enabled'],
            'services.dedupe.enabled MUST default to true when DEDUPE_ENABLED is not set. '
            . 'This is the kill-switch contract for the safety-net pattern (SCN-5.1).'
        );
    }
}
