<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Enums\SiteValidationStatus;
use App\Jobs\ValidateScraperSite;
use App\Models\Pais;
use App\Models\SitioWeb;
use App\Services\Scraper\SiteManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class SiteManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Pais::query()->updateOrCreate(['codigo' => 'BO'], ['nombre' => 'Bolivia', 'activo' => true]);
    }

    public function test_create_stays_inactive_pending_and_dispatches_validation(): void
    {
        Bus::fake();

        $site = app(SiteManagementService::class)->save(null, $this->siteData());

        $this->assertFalse($site->activo);
        $this->assertTrue($site->activation_requested);
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        Bus::assertDispatched(ValidateScraperSite::class, fn (ValidateScraperSite $job): bool => $job->siteId === $site->id);
    }

    public function test_url_or_selector_edit_invalidates_validation_and_dispatches_new_token(): void
    {
        Bus::fake();
        $site = SitioWeb::factory()->create(['url' => 'https://old.test/']);

        app(SiteManagementService::class)->save($site, $this->siteData([
            'url' => 'https://new.test/',
            'selector_links' => '.articles a',
        ]));

        $site->refresh();
        $this->assertFalse($site->activo);
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        $this->assertNotNull($site->validation_token);
        Bus::assertDispatched(ValidateScraperSite::class, fn (ValidateScraperSite $job): bool => $job->validationToken === $site->validation_token);
    }

    public function test_unchanged_orphaned_pending_sites_start_new_validation_generations_and_dispatch_once_each(): void
    {
        Bus::fake();
        $tokenMissing = SitioWeb::factory()->create([
            'url' => 'https://token-missing.test/',
            'selector_links' => null,
            'selector_article' => null,
            'activo' => false,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => null,
            'validation_requested_at' => now()->subDay(),
        ]);
        $timestampMissing = SitioWeb::factory()->create([
            'url' => 'https://timestamp-missing.test/',
            'selector_links' => null,
            'selector_article' => null,
            'activo' => false,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '17171717-1717-4717-8717-171717171717',
            'validation_requested_at' => null,
        ]);
        $service = app(SiteManagementService::class);

        $service->save($tokenMissing, $this->siteData(['url' => 'https://token-missing.test/']));
        $service->save($timestampMissing, $this->siteData(['url' => 'https://timestamp-missing.test/']));

        $tokenMissing->refresh();
        $timestampMissing->refresh();
        $this->assertNotNull($tokenMissing->validation_token);
        $this->assertTrue($tokenMissing->validation_requested_at->isToday());
        $this->assertNotSame('17171717-1717-4717-8717-171717171717', $timestampMissing->validation_token);
        $this->assertNotNull($timestampMissing->validation_requested_at);
        Bus::assertDispatchedTimes(ValidateScraperSite::class, 2);
        Bus::assertDispatched(ValidateScraperSite::class, fn (ValidateScraperSite $job): bool => $job->siteId === $tokenMissing->id
            && $job->validationToken === $tokenMissing->validation_token);
        Bus::assertDispatched(ValidateScraperSite::class, fn (ValidateScraperSite $job): bool => $job->siteId === $timestampMissing->id
            && $job->validationToken === $timestampMissing->validation_token);
    }

    public function test_unchanged_recoverable_pending_site_does_not_start_or_dispatch_another_generation(): void
    {
        Bus::fake();
        $requestedAt = now()->subMinute();
        $site = SitioWeb::factory()->create([
            'url' => 'https://recoverable.test/',
            'selector_links' => null,
            'selector_article' => null,
            'activo' => false,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '18181818-1818-4818-8818-181818181818',
            'validation_requested_at' => $requestedAt,
        ]);
        $requestedAt = $site->validation_requested_at->copy();

        app(SiteManagementService::class)->save($site, $this->siteData(['url' => 'https://recoverable.test/']));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        $this->assertSame('18181818-1818-4818-8818-181818181818', $site->validation_token);
        $this->assertTrue($site->validation_requested_at->equalTo($requestedAt));
        $this->assertFalse($site->activo);
        Bus::assertNotDispatched(ValidateScraperSite::class);
    }

    public function test_manual_retry_resets_failed_state_and_dispatches(): void
    {
        Bus::fake();
        $site = SitioWeb::factory()->create([
            'activo' => false,
            'validation_status' => SiteValidationStatus::Failed,
            'validation_diagnostic' => 'Bloqueado',
        ]);

        app(SiteManagementService::class)->retry($site);

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        $this->assertSame('Validación pendiente de ejecución.', $site->validation_diagnostic);
        Bus::assertDispatched(ValidateScraperSite::class);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function siteData(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://news.test/',
            'nombre' => 'Noticias Test',
            'pais' => 'BO',
            'selector_links' => '',
            'selector_article' => '',
            'activo' => true,
        ], $overrides);
    }
}
