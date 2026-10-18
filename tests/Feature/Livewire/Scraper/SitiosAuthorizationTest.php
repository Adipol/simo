<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Enums\SiteValidationStatus;
use App\Jobs\ValidateScraperSite;
use App\Livewire\Scraper\Sitios;
use App\Models\Pais;
use App\Models\SitioWeb;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

final class SitiosAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesPermisosSeeder::class);
        Pais::query()->firstOrCreate(['codigo' => 'BO'], ['nombre' => 'Bolivia', 'activo' => true]);
    }

    public function test_user_without_gestionar_sitios_cannot_retry_validation_or_mutate_site(): void
    {
        Bus::fake();
        $operador = User::factory()->create(['activo' => true]);
        $operador->assignRole('operador');
        $site = SitioWeb::factory()->create([
            'activo' => true,
            'validation_status' => SiteValidationStatus::Failed,
            'validation_token' => '11111111-1111-4111-8111-111111111111',
            'validation_diagnostic' => 'Bloqueado',
        ]);

        Livewire::actingAs($operador)
            ->test(Sitios::class)
            ->call('reintentarValidacion', $site->id)
            ->assertForbidden();

        $site->refresh();
        $this->assertTrue($site->activo);
        $this->assertSame(SiteValidationStatus::Failed, $site->validation_status);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $site->validation_token);
        $this->assertSame('Bloqueado', $site->validation_diagnostic);
        Bus::assertNothingDispatched();
    }

    public function test_user_without_gestionar_sitios_cannot_save_site_or_dispatch_validation(): void
    {
        Bus::fake();
        $operador = User::factory()->create(['activo' => true]);
        $operador->assignRole('operador');

        Livewire::actingAs($operador)
            ->test(Sitios::class)
            ->set('url', 'https://unauthorized.example.com')
            ->set('nombre', 'Unauthorized site')
            ->set('pais', 'BO')
            ->call('guardar')
            ->assertForbidden();

        $this->assertDatabaseMissing('sitios_web', ['url' => 'https://unauthorized.example.com']);
        Bus::assertNothingDispatched();
    }

    public function test_user_without_gestionar_sitios_cannot_toggle_site_or_dispatch_validation(): void
    {
        Bus::fake();
        $operador = User::factory()->create(['activo' => true]);
        $operador->assignRole('operador');
        $site = SitioWeb::factory()->create([
            'activo' => false,
            'activation_requested' => false,
            'validation_status' => SiteValidationStatus::Failed,
            'validation_token' => '11111111-1111-4111-8111-111111111111',
        ]);

        Livewire::actingAs($operador)
            ->test(Sitios::class)
            ->call('toggleActivo', $site->id)
            ->assertForbidden();

        $site->refresh();
        $this->assertFalse($site->activo);
        $this->assertFalse($site->activation_requested);
        $this->assertSame(SiteValidationStatus::Failed, $site->validation_status);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $site->validation_token);
        Bus::assertNothingDispatched();
    }

    public function test_user_with_gestionar_sitios_can_save_site_and_dispatch_validation(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(Sitios::class)
            ->set('url', 'https://authorized.example.com')
            ->set('nombre', 'Authorized site')
            ->set('pais', 'BO')
            ->call('guardar')
            ->assertOk();

        $site = SitioWeb::query()->where('url', 'https://authorized.example.com')->sole();
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        Bus::assertDispatched(ValidateScraperSite::class, fn (ValidateScraperSite $job): bool => $job->siteId === $site->id
            && $job->validationToken === $site->validation_token);
    }

    public function test_user_with_gestionar_sitios_can_toggle_active_site(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');
        $site = SitioWeb::factory()->create([
            'activo' => true,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Valid,
        ]);

        Livewire::actingAs($admin)
            ->test(Sitios::class)
            ->call('toggleActivo', $site->id)
            ->assertOk();

        $site->refresh();
        $this->assertFalse($site->activo);
        $this->assertFalse($site->activation_requested);
        Bus::assertNothingDispatched();
    }

    public function test_user_with_gestionar_sitios_can_retry_validation(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['activo' => true]);
        $admin->assignRole('admin');
        $site = SitioWeb::factory()->create([
            'activo' => true,
            'validation_status' => SiteValidationStatus::Failed,
        ]);

        Livewire::actingAs($admin)
            ->test(Sitios::class)
            ->call('reintentarValidacion', $site->id)
            ->assertOk();

        $site->refresh();
        $this->assertFalse($site->activo);
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        Bus::assertDispatched(ValidateScraperSite::class, fn (ValidateScraperSite $job): bool => $job->siteId === $site->id
            && $job->validationToken === $site->validation_token);
    }
}
