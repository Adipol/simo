<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Models\LogFuenteRun;
use Tests\Support\PepMonitorParser;
use Tests\TestCase;

/**
 * Contract A: Python pep_monitor.py emitted estados ⊆ PHP VALID_ESTADOS
 *
 * Guards against the bug class documented in PRs #5, #11: a new estado added
 * to pep_monitor.py but not to LogFuenteRun::VALID_ESTADOS would cause silent
 * data drift — the Python scraper writes values that the PHP layer doesn't
 * know about, making those runs invisible to all dashboard filters.
 *
 * If this test FAILS on first run (without any sanity mutation), that is a
 * REAL latent bug discovery — do NOT patch the test to force green.
 * Add the missing estado to LogFuenteRun::VALID_ESTADOS.
 */
class PythonPhpEstadosContractTest extends TestCase
{
    /**
     * Every estado value that pep_monitor.py can write to `log_fuente_runs`
     * must be declared in LogFuenteRun::VALID_ESTADOS.
     *
     * Uses PepMonitorParser to extract the literal estado values from the
     * Python source (excluding log_scripts valores — see parser docblock).
     */
    public function test_python_emitted_estados_son_subset_de_valid_estados(): void
    {
        $emitted = PepMonitorParser::emittedEstados();
        $valid = LogFuenteRun::VALID_ESTADOS;

        $missing = array_diff($emitted, $valid);

        $this->assertEmpty(
            $missing,
            'Python emits estados not declared in LogFuenteRun::VALID_ESTADOS: '
            . implode(', ', $missing)
            . '. Add them to VALID_ESTADOS or remove them from pep_monitor.py.'
        );
    }
}
