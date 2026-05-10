<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR1.4 — T51, T52, T53: Exhaustive permission tests for Dashboard.
 *
 * T51: Admin/supervisor see full health strip; operator cannot see details.
 * T52: can_see_details=false means no sensitive HTML fragments in DOM.
 * T53: @if($health->can_see_details) Blade gate — specific data-detail attr absent.
 */
class DashboardPermissionTest extends TestCase
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

    // ─── T51: Role-based health strip rendering ───────────────────────────────

    /**
     * Admin sees the full health strip including queue depth detail section.
     */
    public function test_admin_sees_full_health_strip_with_queue_depth_detail(): void
    {
        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->html();

        // Admin has can_see_details=true → queue-depth-detail wrapper is rendered
        $this->assertStringContainsString('queue-depth-detail', $html);
        // Admin sees the Cola Gemini label WITH value text
        $this->assertStringContainsString('Cola Gemini', $html);
    }

    /**
     * Supervisor has 'ver dashboard estadisticas' → sees queue depth detail.
     */
    public function test_supervisor_sees_full_health_strip_with_queue_depth_detail(): void
    {
        $supervisor = $this->makeSupervisor();

        $html = Livewire::actingAs($supervisor)
            ->test(Dashboard::class)
            ->html();

        $this->assertStringContainsString('queue-depth-detail', $html);
    }

    /**
     * Operator does NOT see the queue depth number section.
     * The span#queue-depth-detail is completely absent from the DOM.
     */
    public function test_operator_does_not_see_queue_depth_detail_in_dom(): void
    {
        $operador = $this->makeOperador();

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // The detail wrapper is absent — not hidden, completely absent
        $this->assertStringNotContainsString('queue-depth-detail', $html);
    }

    /**
     * Operator does NOT see the analytics section toggle button.
     */
    public function test_operator_does_not_see_analytics_toggle_button(): void
    {
        $operador = $this->makeOperador();

        // Full HTTP page response — analytics button lives in the layout
        $this->actingAs($operador)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Ver estadísticas');
    }

    /**
     * Operator clicking toggleEstadisticas gets 403 Forbidden.
     */
    public function test_operator_toggle_estadisticas_returns_403(): void
    {
        $operador = $this->makeOperador();

        Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->call('toggleEstadisticas')
            ->assertForbidden();
    }

    /**
     * Unauthenticated user accessing dashboard route is redirected to login.
     */
    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    // ─── T52: can_see_details=false → sensitive HTML absent from DOM ──────────

    /**
     * When can_see_details is false, the queue depth numbers are NOT in the DOM.
     *
     * Uses assertDontSeeHtml to match specific HTML fragments (not just text)
     * because the number "0" could appear elsewhere legitimately.
     */
    public function test_operator_html_has_no_queue_depth_numbers(): void
    {
        $operador = $this->makeOperador();

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // The value text pattern "N en cola" only appears inside queue-depth-detail
        // which is completely absent for operators
        $this->assertDoesNotMatchRegularExpression(
            '/\d+\s+en cola/',
            $html,
            'Operator HTML must not contain queue depth number pattern "N en cola"'
        );
    }

    /**
     * Admin HTML DOES contain queue depth value so the test above is meaningful.
     *
     * Triangulation: proves the operator assertion tests real logic,
     * not that the text is simply never rendered.
     */
    public function test_admin_html_contains_queue_depth_value_pattern(): void
    {
        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->html();

        // Admin sees "0 en cola" (or any digit) in the health strip
        $this->assertMatchesRegularExpression(
            '/\d+\s+en cola/',
            $html,
            'Admin HTML must contain queue depth "N en cola" pattern'
        );
    }

    // ─── T53: @if($health->can_see_details) Blade gate — no class="hidden" ────

    /**
     * Verify that the permission gate uses @if Blade conditional, NOT CSS hiding.
     *
     * When can_see_details=false, the specific data attribute used to anchor
     * permission-gating tests (id="queue-depth-detail") MUST be completely absent.
     *
     * This proves the gate is a full server-side @if, not a JS/CSS toggle.
     */
    public function test_queue_depth_detail_id_completely_absent_for_operator(): void
    {
        $operador = $this->makeOperador();

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // Strict: the id attribute itself must not exist in the output
        $this->assertStringNotContainsString('id="queue-depth-detail"', $html);
    }

    /**
     * Triangulation: the queue-depth-detail id IS present for admin.
     * Confirms the assertion above tests real conditional rendering.
     */
    public function test_queue_depth_detail_id_present_for_admin(): void
    {
        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->html();

        $this->assertStringContainsString('id="queue-depth-detail"', $html);
    }

    /**
     * Verify hidden class pattern is NOT used for permission gating.
     *
     * The health strip must NOT render queue-depth-detail with a hidden
     * class when the user lacks permissions — it must be absent entirely.
     */
    public function test_no_hidden_class_used_for_queue_depth_gate(): void
    {
        $operador = $this->makeOperador();

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // queue-depth-detail must not appear at all (not even hidden)
        $this->assertDoesNotMatchRegularExpression(
            '/queue-depth-detail[^>]*class[^>]*hidden/',
            $html,
            'Permission gating must use @if not class="hidden"'
        );
        $this->assertStringNotContainsString('queue-depth-detail', $html);
    }
}
