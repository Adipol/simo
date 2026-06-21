<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scripts;

use App\Livewire\Scripts\Estado;
use App\Models\ConfigScript;
use App\Models\LogScript;
use App\Models\User;
use Database\Seeders\ConfigScriptGacetaSeeder;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Estado de Scripts — gaceta card.
 *
 * SCNs covered:
 *   AUTH   Unauthenticated → redirect; operador (with ver estado scripts) → renders.
 *   T1     Gaceta card renders with last execution data when a log entry exists.
 *   T2     Gaceta card renders "Sin registros" when no log entry exists.
 *   T3     Gaceta card shows intervalo from config_scripts.
 *   T4     Existing scraper and pep_monitor cards are unaffected (regression).
 */
class EstadoTest extends TestCase
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

    private function seedGacetaConfig(): ConfigScript
    {
        $this->seed(ConfigScriptGacetaSeeder::class);

        return ConfigScript::where('script', 'gaceta')->firstOrFail();
    }

    private function createGacetaLog(array $overrides = []): LogScript
    {
        return LogScript::create(array_merge([
            'script'           => 'gaceta',
            'estado'           => 'completado',
            'inicio'           => now()->subHour(),
            'fin'              => now()->subMinutes(58),
            'duracion_segundos' => 120,
            'items_procesados' => 42,
            'items_resultado'  => 3,
            'errores'          => 0,
            'mensaje_error'    => null,
        ], $overrides));
    }

    // ─── AUTH ─────────────────────────────────────────────────────────────────

    /**
     * Unauthenticated user accessing scripts/estado is redirected to login.
     */
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('scripts.estado'))
            ->assertRedirect(route('login'));
    }

    /**
     * Operador with 'ver estado scripts' permission can access the page.
     */
    public function test_operador_can_view_estado_scripts(): void
    {
        $operador = $this->makeOperador();

        $this->actingAs($operador)
            ->get(route('scripts.estado'))
            ->assertOk();
    }

    // ─── T1: Gaceta card renders with last execution data ─────────────────────

    /**
     * Gaceta card shows last execution datetime when a log entry exists.
     */
    public function test_gaceta_card_shows_last_execution_date(): void
    {
        $this->seedGacetaConfig();
        $log = $this->createGacetaLog();

        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Estado::class)
            ->html();

        $this->assertStringContainsString(
            $log->inicio->format('d/m/Y H:i'),
            $html,
            'Gaceta card must show the last execution date from log_scripts'
        );
    }

    /**
     * Gaceta card shows the execution estado (completado/error/etc.) badge.
     *
     * Triangulation: different estado value produces different badge text.
     */
    public function test_gaceta_card_shows_estado_badge(): void
    {
        $this->seedGacetaConfig();
        $this->createGacetaLog(['estado' => 'error', 'mensaje_error' => 'Timeout de conexion']);

        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Estado::class)
            ->html();

        $this->assertStringContainsString('Error', $html);
    }

    // ─── T2: Gaceta card with no log entry ────────────────────────────────────

    /**
     * Gaceta card renders "Sin registros" when no log entry exists.
     */
    public function test_gaceta_card_shows_sin_registros_when_no_log(): void
    {
        $this->seedGacetaConfig();
        // No log entry created

        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Estado::class)
            ->html();

        $this->assertStringContainsString(
            'Sin registros',
            $html,
            'Gaceta card must show "Sin registros" when no log entry exists'
        );
    }

    // ─── T3: Gaceta card shows config data ────────────────────────────────────

    /**
     * Gaceta card shows the configured intervalo from config_scripts.
     */
    public function test_gaceta_card_shows_intervalo_from_config(): void
    {
        $this->seedGacetaConfig();

        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Estado::class)
            ->html();

        // The gaceta config has intervalo_minutos=60 → "1 hora" or "60 min"
        $this->assertMatchesRegularExpression(
            '/60\s*min|1\s*hora/',
            $html,
            'Gaceta card must show the intervalo_minutos from config_scripts'
        );
    }

    // ─── T4: Regression — existing cards unaffected ───────────────────────────

    /**
     * Adding gaceta does not break scraper and pep_monitor card rendering.
     *
     * Triangulation: proves gaceta addition is additive, not destructive.
     */
    public function test_scraper_and_pep_cards_still_render_after_gaceta_added(): void
    {
        $this->seedGacetaConfig();

        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Estado::class)
            ->html();

        $this->assertStringContainsString('Scraper Web', $html);
        $this->assertStringContainsString('PEP Monitor', $html);
    }

    /**
     * Gaceta Oficial card title is present in the rendered HTML.
     */
    public function test_gaceta_card_title_is_rendered(): void
    {
        $this->seedGacetaConfig();

        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Estado::class)
            ->html();

        $this->assertStringContainsString('Gaceta Oficial', $html);
    }
}
