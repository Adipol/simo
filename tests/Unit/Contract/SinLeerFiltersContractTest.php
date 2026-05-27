<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Support\SinLeerFilters;
use Tests\TestCase;

/**
 * Contract C: SinLeerFilters::CONDITIONS declares exactly the 5 canonical
 * sin_leer filter conditions consumed by DashboardSummaryService::sinLeerBaseQuery().
 *
 * Guards against the drift bug class documented in PR #5, #11, #13: each time
 * a filter condition was added or changed in the bandeja view, the dashboard
 * KPI silently diverged because the two independently-maintained query chains
 * fell out of sync. Extracting all 5 conditions to a single SSOT
 * (SinLeerFilters::CONDITIONS) ensures any future change is a single-line edit
 * that CONTRACT C will immediately flag if the count or shape diverges.
 *
 * Asserts on the constant's data structure only — does NOT instantiate a
 * Builder or execute any database query.
 */
class SinLeerFiltersContractTest extends TestCase
{
    /**
     * SinLeerFilters::CONDITIONS must declare exactly 5 filter entries.
     * If a new filter is added to sinLeerBaseQuery(), it must also be added
     * to CONDITIONS — otherwise this test fails, preventing silent drift.
     */
    public function test_sin_leer_filters_tiene_exactamente_5_condiciones(): void
    {
        $this->assertCount(
            5,
            SinLeerFilters::CONDITIONS,
            'SinLeerFilters::CONDITIONS must have exactly 5 entries. '
            . 'Add or remove entries to match sinLeerBaseQuery() filter conditions.'
        );
    }

    /**
     * The 5 canonical filter conditions must be present in CONDITIONS.
     * Each condition is asserted by type + target (and value when applicable)
     * to ensure the structure matches what sinLeerBaseQuery() applies.
     *
     * The canonical conditions are:
     *   1. leido = false
     *   2. descartado = false
     *   3. scope: noArchivado
     *   4. gemini_analyzed = true
     *   5. scope: onlyPrimaries
     */
    public function test_sin_leer_filters_contiene_las_5_condiciones_canonicas(): void
    {
        $conditions = SinLeerFilters::CONDITIONS;

        // Build an index for easy assertion
        $wheresByTarget = [];
        $scopesByTarget = [];
        foreach ($conditions as $c) {
            if ($c['type'] === 'where') {
                $wheresByTarget[$c['target']] = $c['value'];
            } elseif ($c['type'] === 'scope') {
                $scopesByTarget[] = $c['target'];
            }
        }

        // 1. leido = false
        $this->assertArrayHasKey(
            'leido',
            $wheresByTarget,
            "Missing where condition 'leido' in SinLeerFilters::CONDITIONS."
        );
        $this->assertFalse(
            $wheresByTarget['leido'],
            "Condition 'leido' must have value false."
        );

        // 2. descartado = false
        $this->assertArrayHasKey(
            'descartado',
            $wheresByTarget,
            "Missing where condition 'descartado' in SinLeerFilters::CONDITIONS."
        );
        $this->assertFalse(
            $wheresByTarget['descartado'],
            "Condition 'descartado' must have value false."
        );

        // 3. gemini_analyzed = true
        $this->assertArrayHasKey(
            'gemini_analyzed',
            $wheresByTarget,
            "Missing where condition 'gemini_analyzed' in SinLeerFilters::CONDITIONS."
        );
        $this->assertTrue(
            $wheresByTarget['gemini_analyzed'],
            "Condition 'gemini_analyzed' must have value true."
        );

        // 4. scope: noArchivado
        $this->assertContains(
            'noArchivado',
            $scopesByTarget,
            "Missing scope 'noArchivado' in SinLeerFilters::CONDITIONS."
        );

        // 5. scope: onlyPrimaries
        $this->assertContains(
            'onlyPrimaries',
            $scopesByTarget,
            "Missing scope 'onlyPrimaries' in SinLeerFilters::CONDITIONS."
        );
    }
}
