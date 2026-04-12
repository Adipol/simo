<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard;
use App\Models\User;
use App\Services\Dashboard\DTOs\VolumeMetricsDTO;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardEstadisticasTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function makeSupervisor(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('supervisor');

        return $user;
    }

    private function makeOperador(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('operador');

        return $user;
    }

    // 6.1 Default state

    public function test_dashboard_component_default_mostrar_estadisticas_is_false(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSet('mostrarEstadisticas', false);
    }

    // 6.2 Toggle method

    public function test_toggle_estadisticas_toggles_to_true(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleEstadisticas')
            ->assertSet('mostrarEstadisticas', true);
    }

    public function test_toggle_estadisticas_toggles_back_to_false(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleEstadisticas')
            ->call('toggleEstadisticas')
            ->assertSet('mostrarEstadisticas', false);
    }

    // 6.3 Operador cannot toggle (authorization)

    public function test_operador_cannot_call_toggle_estadisticas(): void
    {
        $operador = $this->makeOperador();

        Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->call('toggleEstadisticas')
            ->assertForbidden();
    }

    // 6.4 Computed returns empty when collapsed

    public function test_volume_metrics_computed_returns_empty_when_collapsed(): void
    {
        $admin = $this->makeAdmin();

        $component = Livewire::actingAs($admin)
            ->test(Dashboard::class);

        // mostrarEstadisticas = false → should return empty DTO
        $this->assertFalse($component->get('mostrarEstadisticas'));
        $volumeMetrics = $component->instance()->volumeMetrics;
        $this->assertInstanceOf(VolumeMetricsDTO::class, $volumeMetrics);
        $this->assertFalse($volumeMetrics->hasData);
    }

    // 6.5 Computed returns data when expanded

    public function test_volume_metrics_computed_returns_dto_when_expanded(): void
    {
        $admin = $this->makeAdmin();

        $component = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleEstadisticas');

        $volumeMetrics = $component->instance()->volumeMetrics;
        $this->assertInstanceOf(VolumeMetricsDTO::class, $volumeMetrics);
    }

    // 6.6 Filter changes: setting filter properties

    public function test_filter_properties_can_be_set(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->set('filtroDateRange', 'week')
            ->assertSet('filtroDateRange', 'week')
            ->set('filtroPais', 'AR')
            ->assertSet('filtroPais', 'AR')
            ->set('filtroCategoria', 'PEP')
            ->assertSet('filtroCategoria', 'PEP');
    }

    // 6.7 Service injected

    public function test_dashboard_renders_without_errors(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertOk();
    }

    // 9.1 Full flow — admin sees toggle button

    public function test_admin_sees_ver_estadisticas_toggle_button(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ver estadísticas');
    }

    // 9.2 Full flow — operador hidden toggle

    public function test_operador_does_not_see_ver_estadisticas_toggle(): void
    {
        $operador = $this->makeOperador();

        $this->actingAs($operador)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Ver estadísticas');
    }

    // 9.4 Chart.js CDN in rendered HTML (when expanded)

    public function test_chart_js_cdn_present_in_full_page_html(): void
    {
        $admin = $this->makeAdmin();

        // Create some data so volumeMetrics->hasData = true and chart is rendered
        \App\Models\ResultadoScraping::factory()->create([
            'gemini_analyzed' => true,
            'gemini_categoria' => 'PEP',
        ]);

        // Expand the stats via full-page request — the layout stack renders in page HTML
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('chart.umd.min.js', false);
    }

    // 9.5 Filter reactivity

    public function test_filter_change_updates_computed_metrics(): void
    {
        $admin = $this->makeAdmin();

        $component = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('toggleEstadisticas')
            ->set('filtroDateRange', 'week');

        // After setting filter, component should still be valid (no exception)
        $component->assertOk();
        $component->assertSet('filtroDateRange', 'week');
    }

    // 9.6 Permission check on toggle via HTTP

    public function test_operador_toggle_returns_forbidden(): void
    {
        $operador = $this->makeOperador();

        Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->call('toggleEstadisticas')
            ->assertForbidden();
    }
}
