<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scripts;

use App\Livewire\Scripts\Configuracion;
use App\Models\ConfigScript;
use App\Models\User;
use Database\Seeders\ConfigScriptGacetaSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Configuracion de Scripts — gaceta config form.
 *
 * SCNs covered:
 *   AUTH   Unauthenticated → redirect; operador (no configurar scripts) → 403; admin → 200.
 *   T1     Gaceta config loads into component props on mount.
 *   T2     Saving updates the config_scripts gaceta row in the DB.
 *   T3     Validation rejects invalid gaceta intervalo.
 *   T4     Gaceta config section renders in the form view.
 *   T5     Regression — scraper and pep_monitor config unaffected.
 */
class ConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function makeOperador(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('operador');

        return $user;
    }

    private function seedGacetaConfig(array $overrides = []): ConfigScript
    {
        $this->seed(ConfigScriptGacetaSeeder::class);

        if ($overrides) {
            ConfigScript::where('script', 'gaceta')->update($overrides);
        }

        return ConfigScript::where('script', 'gaceta')->firstOrFail();
    }

    // ─── AUTH ─────────────────────────────────────────────────────────────────

    /**
     * Unauthenticated user accessing scripts/configuracion is redirected to login.
     */
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('scripts.configuracion'))
            ->assertRedirect(route('login'));
    }

    /**
     * Operador without 'configurar scripts' permission gets 403.
     */
    public function test_operador_cannot_access_configuracion(): void
    {
        $operador = $this->makeOperador();

        $this->actingAs($operador)
            ->get(route('scripts.configuracion'))
            ->assertForbidden();
    }

    /**
     * Admin with 'configurar scripts' permission can access the page.
     */
    public function test_admin_can_access_configuracion(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('scripts.configuracion'))
            ->assertOk();
    }

    // ─── T1: Gaceta config loads on mount ─────────────────────────────────────

    /**
     * On mount, gacetaIntervalo is populated from config_scripts.
     */
    public function test_gaceta_intervalo_loaded_on_mount(): void
    {
        $this->seedGacetaConfig(['intervalo_minutos' => 90]);
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->assertSet('gacetaIntervalo', 90);
    }

    /**
     * On mount, gacetaHabilitado is populated from config_scripts.
     *
     * Triangulation: different habilitado value produces different prop state.
     */
    public function test_gaceta_habilitado_loaded_on_mount(): void
    {
        $this->seedGacetaConfig(['habilitado' => false]);
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->assertSet('gacetaHabilitado', false);
    }

    /**
     * On mount, gacetaTimeout is populated from config_scripts.
     */
    public function test_gaceta_timeout_loaded_on_mount(): void
    {
        $this->seedGacetaConfig(['timeout_minutos' => 45]);
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->assertSet('gacetaTimeout', 45);
    }

    // ─── T2: Saving updates DB row ────────────────────────────────────────────

    /**
     * Calling guardar() persists the new gacetaIntervalo to config_scripts.
     */
    public function test_guardar_persists_gaceta_intervalo_to_db(): void
    {
        $this->seedGacetaConfig(['intervalo_minutos' => 60]);
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->set('gacetaIntervalo', 120)
            ->call('guardar');

        $row = ConfigScript::where('script', 'gaceta')->first();
        $this->assertSame(120, $row->intervalo_minutos);
    }

    /**
     * Calling guardar() persists gacetaHabilitado=false to config_scripts.
     *
     * Triangulation: disabling is the complementary case to enabling.
     */
    public function test_guardar_persists_gaceta_habilitado_false_to_db(): void
    {
        $this->seedGacetaConfig(['habilitado' => true]);
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->set('gacetaHabilitado', false)
            ->call('guardar');

        $row = ConfigScript::where('script', 'gaceta')->first();
        $this->assertFalse($row->habilitado);
    }

    /**
     * Calling guardar() shows a success message after saving.
     */
    public function test_guardar_shows_success_message(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->call('guardar')
            ->assertSet('tipoMensaje', 'success');
    }

    // ─── T3: Validation ───────────────────────────────────────────────────────

    /**
     * guardar() rejects gacetaIntervalo below minimum (5 minutes).
     */
    public function test_guardar_rejects_gaceta_intervalo_below_minimum(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->set('gacetaIntervalo', 2)
            ->call('guardar')
            ->assertHasErrors(['gacetaIntervalo']);
    }

    /**
     * guardar() rejects gacetaIntervalo above maximum (1440 minutes).
     *
     * Triangulation: upper bound validation.
     */
    public function test_guardar_rejects_gaceta_intervalo_above_maximum(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->set('gacetaIntervalo', 9999)
            ->call('guardar')
            ->assertHasErrors(['gacetaIntervalo']);
    }

    // ─── T4: Gaceta section renders in the view ───────────────────────────────

    /**
     * The gaceta configuration section is rendered in the form view.
     */
    public function test_gaceta_config_section_renders_in_view(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->html();

        $this->assertStringContainsString('Gaceta Oficial', $html);
    }

    /**
     * The gaceta intervalo field is bound with wire:model in the rendered HTML.
     */
    public function test_gaceta_intervalo_field_has_wire_model_binding(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->html();

        $this->assertStringContainsString('gacetaIntervalo', $html);
    }

    // ─── T5: Regression — existing scripts unaffected ─────────────────────────

    /**
     * After adding gaceta, scraper and pep config sections still render.
     */
    public function test_scraper_and_pep_sections_still_render(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Configuracion::class)
            ->html();

        $this->assertStringContainsString('Scraper Web', $html);
        $this->assertStringContainsString('PEP Monitor', $html);
    }

    /**
     * Saving gaceta config does not overwrite the scraper config.
     */
    public function test_saving_gaceta_does_not_affect_scraper_config(): void
    {
        $this->seedGacetaConfig();
        $admin = $this->makeAdmin();

        $component = Livewire::actingAs($admin)->test(Configuracion::class);
        $originalScraperIntervalo = $component->get('scraperIntervalo');

        $component->set('gacetaIntervalo', 120)->call('guardar');

        $scraperRow = ConfigScript::where('script', 'scraper')->first();
        $this->assertSame($originalScraperIntervalo, $scraperRow->intervalo_minutos);
    }
}
