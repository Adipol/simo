<?php

declare(strict_types=1);

namespace Tests\Feature\View\Dashboard;

use App\Services\Dashboard\DTOs\GeminiQuotaDTO;
use App\Services\Dashboard\DTOs\LatencyDTO;
use App\Services\Dashboard\DTOs\PipelineHealthDTO;
use App\Services\Dashboard\DTOs\QueueDepthDTO;
use App\Services\Dashboard\DTOs\ScraperStatusDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T39 — RED tests for health-strip and health-pill Blade components.
 *
 * Critical: detail markup must NOT be in the DOM for non-admin users.
 * NOT hidden with class="hidden" — must be inside @if guard.
 */
class HealthStripTest extends TestCase
{
    use RefreshDatabase;

    private function makeHealth(bool $canSeeDetails): PipelineHealthDTO
    {
        return new PipelineHealthDTO(
            scraper: new ScraperStatusDTO(
                name: 'scraper',
                state: 'completado',
                last_run: new \DateTimeImmutable('2026-05-10 09:00:00'),
                duration_seconds: 45.3,
                status: 'ok',
            ),
            pep_monitor: new ScraperStatusDTO(
                name: 'pep_monitor',
                state: 'completado',
                last_run: new \DateTimeImmutable('2026-05-10 08:00:00'),
                duration_seconds: 12.1,
                status: 'ok',
            ),
            queues: new QueueDepthDTO(
                gemini_pro_pending: 3,
                gemini_flash_pending: 5,
                other_pending: 1,
            ),
            latency: LatencyDTO::unavailable(),
            quota: GeminiQuotaDTO::unavailable(),
            can_see_details: $canSeeDetails,
        );
    }

    // ─── All users see pill labels ────────────────────────────────────────────

    public function test_health_strip_shows_scraper_label(): void
    {
        $html = view('components.dashboard.health-strip', [
            'health' => $this->makeHealth(false),
        ])->render();

        $this->assertStringContainsString('Scraper', $html);
    }

    public function test_health_strip_shows_pep_monitor_label(): void
    {
        $html = view('components.dashboard.health-strip', [
            'health' => $this->makeHealth(false),
        ])->render();

        $this->assertStringContainsString('PEP Monitor', $html);
    }

    public function test_health_strip_shows_cola_gemini_label(): void
    {
        $html = view('components.dashboard.health-strip', [
            'health' => $this->makeHealth(false),
        ])->render();

        $this->assertStringContainsString('Cola Gemini', $html);
    }

    // ─── Non-admin: queue depth detail NOT in DOM ─────────────────────────────

    public function test_non_admin_does_not_see_queue_depth_detail(): void
    {
        $html = view('components.dashboard.health-strip', [
            'health' => $this->makeHealth(false),
        ])->render();

        // The detail section must not be in the DOM at all
        $this->assertStringNotContainsString('queue-depth-detail', $html);
        // Queue numbers (3, 5) must not leak for non-admin
        $this->assertStringNotContainsString('gemini_pro', $html);
    }

    // ─── Admin: queue depth detail IS in DOM ──────────────────────────────────

    public function test_admin_sees_queue_depth_detail(): void
    {
        $html = view('components.dashboard.health-strip', [
            'health' => $this->makeHealth(true),
        ])->render();

        $this->assertStringContainsString('queue-depth-detail', $html);
    }

    // ─── Latency unavailable shows "Recolectando datos…" ─────────────────────

    public function test_latency_unavailable_shows_collecting_message(): void
    {
        $html = view('components.dashboard.health-strip', [
            'health' => $this->makeHealth(false),
        ])->render();

        $this->assertStringContainsString('Recolectando', $html);
    }
}
