<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\GeminiQuotaDTO;
use App\Services\Dashboard\DTOs\LatencyDTO;
use App\Services\Dashboard\DTOs\PipelineHealthDTO;
use App\Services\Dashboard\DTOs\QueueDepthDTO;
use App\Services\Dashboard\DTOs\ScraperStatusDTO;
use Tests\TestCase;

class HealthDTOsTest extends TestCase
{
    // ─── ScraperStatusDTO ────────────────────────────────────────────────────

    public function test_scraper_status_dto_stores_all_fields(): void
    {
        $lastRun = new \DateTimeImmutable('2026-05-10 09:00:00');

        $dto = new ScraperStatusDTO(
            name: 'scraper',
            state: 'running',
            last_run: $lastRun,
            duration_seconds: 12.5,
            status: 'ok',
        );

        $this->assertSame('scraper', $dto->name);
        $this->assertSame('running', $dto->state);
        $this->assertSame($lastRun, $dto->last_run);
        $this->assertEqualsWithDelta(12.5, $dto->duration_seconds, 0.001);
        $this->assertSame('ok', $dto->status);
    }

    public function test_scraper_status_dto_last_run_can_be_null(): void
    {
        $dto = new ScraperStatusDTO(
            name: 'pep_monitor',
            state: 'idle',
            last_run: null,
            duration_seconds: null,
            status: 'no_data',
        );

        $this->assertNull($dto->last_run);
        $this->assertNull($dto->duration_seconds);
        $this->assertSame('no_data', $dto->status);
    }

    public function test_scraper_status_dto_warning_status(): void
    {
        $dto = new ScraperStatusDTO(
            name: 'scraper',
            state: 'idle',
            last_run: new \DateTimeImmutable('2026-05-10 01:00:00'),
            duration_seconds: null,
            status: 'warning',
        );

        $this->assertSame('warning', $dto->status);
    }

    // ─── QueueDepthDTO ───────────────────────────────────────────────────────

    public function test_queue_depth_dto_stores_all_counts(): void
    {
        $dto = new QueueDepthDTO(
            gemini_pro_pending: 3,
            gemini_flash_pending: 5,
            other_pending: 2,
        );

        $this->assertSame(3, $dto->gemini_pro_pending);
        $this->assertSame(5, $dto->gemini_flash_pending);
        $this->assertSame(2, $dto->other_pending);
    }

    public function test_queue_depth_dto_all_zeros(): void
    {
        $dto = new QueueDepthDTO(
            gemini_pro_pending: 0,
            gemini_flash_pending: 0,
            other_pending: 0,
        );

        $this->assertSame(0, $dto->gemini_pro_pending);
        $this->assertSame(0, $dto->gemini_flash_pending);
        $this->assertSame(0, $dto->other_pending);
    }

    // ─── LatencyDTO — available: false stub (PR1) ────────────────────────────

    public function test_latency_dto_available_false_stub(): void
    {
        $dto = new LatencyDTO(
            available: false,
            p50_seconds: null,
            p95_seconds: null,
            sample_size: 0,
        );

        $this->assertFalse($dto->available);
        $this->assertNull($dto->p50_seconds);
        $this->assertNull($dto->p95_seconds);
        $this->assertSame(0, $dto->sample_size);
    }

    public function test_latency_dto_available_true_with_values(): void
    {
        $dto = new LatencyDTO(
            available: true,
            p50_seconds: 1.23,
            p95_seconds: 4.56,
            sample_size: 42,
        );

        $this->assertTrue($dto->available);
        $this->assertEqualsWithDelta(1.23, $dto->p50_seconds, 0.001);
        $this->assertEqualsWithDelta(4.56, $dto->p95_seconds, 0.001);
        $this->assertSame(42, $dto->sample_size);
    }

    // ─── GeminiQuotaDTO — available: false stub (PR1) ───────────────────────

    public function test_gemini_quota_dto_available_false_stub(): void
    {
        $dto = new GeminiQuotaDTO(
            available: false,
            tokens_today: null,
            requests_today: null,
            daily_limit: null,
        );

        $this->assertFalse($dto->available);
        $this->assertNull($dto->tokens_today);
        $this->assertNull($dto->requests_today);
        $this->assertNull($dto->daily_limit);
    }

    public function test_gemini_quota_dto_available_true_with_values(): void
    {
        $dto = new GeminiQuotaDTO(
            available: true,
            tokens_today: 50000,
            requests_today: 120,
            daily_limit: 100000,
        );

        $this->assertTrue($dto->available);
        $this->assertSame(50000, $dto->tokens_today);
        $this->assertSame(120, $dto->requests_today);
        $this->assertSame(100000, $dto->daily_limit);
    }

    // ─── PipelineHealthDTO ───────────────────────────────────────────────────

    public function test_pipeline_health_dto_assembles_all_child_dtos(): void
    {
        $scraper = new ScraperStatusDTO('scraper', 'idle', null, null, 'no_data');
        $pepMonitor = new ScraperStatusDTO('pep_monitor', 'idle', null, null, 'no_data');
        $queues = new QueueDepthDTO(0, 0, 0);
        $latency = new LatencyDTO(false, null, null, 0);
        $quota = new GeminiQuotaDTO(false, null, null, null);

        $dto = new PipelineHealthDTO(
            scraper: $scraper,
            pep_monitor: $pepMonitor,
            queues: $queues,
            latency: $latency,
            quota: $quota,
            can_see_details: false,
        );

        $this->assertSame($scraper, $dto->scraper);
        $this->assertSame($pepMonitor, $dto->pep_monitor);
        $this->assertSame($queues, $dto->queues);
        $this->assertSame($latency, $dto->latency);
        $this->assertSame($quota, $dto->quota);
        $this->assertFalse($dto->can_see_details);
    }

    public function test_pipeline_health_dto_can_see_details_true_for_admin(): void
    {
        $scraper    = new ScraperStatusDTO('scraper', 'idle', null, null, 'ok');
        $pepMonitor = new ScraperStatusDTO('pep_monitor', 'idle', null, null, 'ok');
        $queues     = new QueueDepthDTO(1, 2, 0);
        $latency    = new LatencyDTO(false, null, null, 0);
        $quota      = new GeminiQuotaDTO(false, null, null, null);

        $dto = new PipelineHealthDTO(
            scraper: $scraper,
            pep_monitor: $pepMonitor,
            queues: $queues,
            latency: $latency,
            quota: $quota,
            can_see_details: true,
        );

        $this->assertTrue($dto->can_see_details);
    }
}
