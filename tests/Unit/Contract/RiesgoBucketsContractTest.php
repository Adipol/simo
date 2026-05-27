<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Services\Dashboard\DashboardSummaryService;
use App\Services\Gemini\DTOs\AnalisisCambioDTO;
use Tests\TestCase;

/**
 * Contract D: RIESGO_VALUES (DTO) == RIESGO_BUCKET_KEYS (dashboard)
 *
 * Guards against drift between the riesgo values that Gemini can produce
 * (AnalisisCambioDTO::RIESGO_VALUES) and the riesgo buckets that the dashboard
 * counts in triageStrip() (DashboardSummaryService::RIESGO_BUCKET_KEYS).
 *
 * If these diverge — e.g. Gemini adds a new riesgo level but the dashboard
 * doesn't add a bucket for it — that riesgo level becomes invisible in the
 * triage strip, silently under-counting risk.
 *
 * Asserts on data structures only — does NOT parse Gemini prompts or execute
 * DB queries.
 */
class RiesgoBucketsContractTest extends TestCase
{
    /**
     * The riesgo values Gemini can produce must exactly match the riesgo bucket
     * keys the dashboard triage strip counts. Both must be non-empty and
     * identical (after sorting to eliminate ordering differences).
     */
    public function test_riesgo_values_son_iguales_a_los_buckets_de_triage_strip(): void
    {
        $values = AnalisisCambioDTO::RIESGO_VALUES;
        $buckets = DashboardSummaryService::RIESGO_BUCKET_KEYS;

        $this->assertNotEmpty($values, 'AnalisisCambioDTO::RIESGO_VALUES must not be empty.');
        $this->assertNotEmpty($buckets, 'DashboardSummaryService::RIESGO_BUCKET_KEYS must not be empty.');

        sort($values);
        sort($buckets);

        $this->assertSame(
            $buckets,
            $values,
            'RIESGO_VALUES and RIESGO_BUCKET_KEYS have drifted. '
            . 'Add the missing key to DashboardSummaryService::RIESGO_BUCKET_KEYS or '
            . 'remove it from AnalisisCambioDTO::RIESGO_VALUES. '
            . 'Values: [' . implode(', ', $values) . '] '
            . 'Buckets: [' . implode(', ', $buckets) . ']'
        );
    }
}
