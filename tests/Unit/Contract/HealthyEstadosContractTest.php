<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Models\LogFuenteRun;
use Tests\TestCase;

/**
 * Contract B: HEALTHY_ESTADOS ⊆ VALID_ESTADOS
 *
 * Guards against the bug class documented in PRs #5, #11, #45, #46:
 * a value added to HEALTHY_ESTADOS but not to VALID_ESTADOS would
 * cause silent semantic drift — the service would consider a run "healthy"
 * while the schema-level validation would reject the same estado.
 *
 * These tests assert on PHP constants only — no DB, no app boot required.
 */
class HealthyEstadosContractTest extends TestCase
{
    /**
     * Every value in HEALTHY_ESTADOS must also appear in VALID_ESTADOS.
     * A value in HEALTHY_ESTADOS but not in VALID_ESTADOS is impossible at
     * runtime (the scraper would never write it), making the "healthy" flag
     * unreachable — silent semantic drift.
     */
    public function test_healthy_estados_son_subset_de_valid_estados(): void
    {
        $missing = array_diff(
            LogFuenteRun::HEALTHY_ESTADOS,
            LogFuenteRun::VALID_ESTADOS
        );

        $this->assertEmpty(
            $missing,
            'Values in HEALTHY_ESTADOS but missing from VALID_ESTADOS: '
            . implode(', ', $missing)
            . '. Add them to VALID_ESTADOS or remove them from HEALTHY_ESTADOS.'
        );
    }

    /**
     * The three canonical healthy estados established in PRs #45 and #46 must
     * always be present in HEALTHY_ESTADOS. Removing any of them would silently
     * break the consecutive-failure-streak logic in DashboardSourceHealthService.
     */
    public function test_healthy_estados_contiene_los_3_estados_canonicos(): void
    {
        $canonicos = ['success', 'no_change', 'first_snapshot'];

        foreach ($canonicos as $estado) {
            $this->assertContains(
                $estado,
                LogFuenteRun::HEALTHY_ESTADOS,
                "Canonical healthy estado '{$estado}' is missing from HEALTHY_ESTADOS. "
                . 'This would break the consecutive-failure-streak logic (PR #45/#46).'
            );
        }
    }
}
