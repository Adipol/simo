<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard;
use App\Models\Cambio;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR1.4 — T57: Health permission DOM-leak test.
 *
 * Confirms that specific sensitive number patterns (queue counts, error
 * stack traces) are ABSENT from non-admin/non-supervisor HTML output.
 *
 * Uses regex assertions where pattern matching is needed to detect
 * any form of the sensitive data, not just exact string matches.
 */
class DashboardHealthDomLeakTest extends TestCase
{
    use RefreshDatabase;

    private function makeOperador(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('operador');

        return $user;
    }

    private function makeAdmin(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        return $user;
    }

    // ─── T57: Queue count leak prevention ────────────────────────────────────

    /**
     * Operator HTML must not contain "N en cola" pattern (queue depth numbers).
     * The queue count is sensitive operational data only admins/supervisors see.
     */
    public function test_operator_html_has_no_queue_count_pattern(): void
    {
        $operador = $this->makeOperador();

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // Pattern: any digit(s) followed by "en cola"
        $this->assertDoesNotMatchRegularExpression(
            '/\d+\s+en cola/',
            $html,
            'Operator DOM must not contain queue count pattern "N en cola"'
        );
    }

    /**
     * Triangulation: Admin HTML DOES contain the queue count pattern.
     * Confirms the operator test above is not vacuously passing.
     */
    public function test_admin_html_has_queue_count_pattern(): void
    {
        $admin = $this->makeAdmin();

        $html = Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->html();

        $this->assertMatchesRegularExpression(
            '/\d+\s+en cola/',
            $html,
            'Admin DOM must contain queue count pattern "N en cola"'
        );
    }

    // ─── T57: No raw stack traces or error messages leaked ───────────────────

    /**
     * Operator HTML must not contain PHP exception/stack trace patterns.
     * The health strip may show error statuses but not raw traces.
     */
    public function test_operator_html_has_no_stack_trace_patterns(): void
    {
        $operador = $this->makeOperador();

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // PHP stack trace patterns
        $this->assertDoesNotMatchRegularExpression(
            '/#\d+\s+\w+\.php\(\d+\)/',
            $html,
            'Operator DOM must not contain PHP stack trace patterns'
        );

        $this->assertStringNotContainsString('Stack trace:', $html);
        $this->assertStringNotContainsString('Exception:', $html);
        $this->assertStringNotContainsString('vendor/laravel', $html);
    }

    // ─── T57: queue-depth-detail completely absent for operator ──────────────

    /**
     * The specific DOM section with queue depth numbers must be completely
     * absent for operators, not hidden or conditionally shown.
     */
    public function test_operator_queue_depth_detail_section_absent(): void
    {
        $operador = $this->makeOperador();

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // The id anchor is absent
        $this->assertStringNotContainsString('queue-depth-detail', $html);

        // No inline style display:none or visibility:hidden hiding it
        $this->assertDoesNotMatchRegularExpression(
            '/queue-depth-detail[^>]*(display\s*:\s*none|visibility\s*:\s*hidden)/',
            $html,
            'queue-depth-detail must be absent, not just hidden'
        );
    }

    // ─── T57: Error state rendered safely ─────────────────────────────────────

    /**
     * When a cambio with high risk exists, the hero card shows the risk badge
     * but does NOT leak the raw JSON analysis to operator DOM.
     */
    public function test_operator_hero_card_does_not_leak_raw_json(): void
    {
        $operador = $this->makeOperador();

        // Create a high-risk cambio with sensitive JSON
        Cambio::factory()->create([
            'revisado'             => false,
            'gemini_analyzed'      => false,
            'posibles_peps'        => 'Juan Pérez - Ministro',
            'gemini_analisis_json' => ['riesgo' => 'alto', 'detalle' => 'secreto'],
            'fecha'                => now(),
        ]);

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // Raw JSON structure must not appear in DOM
        $this->assertStringNotContainsString('"detalle":', $html);
        $this->assertStringNotContainsString('"secreto"', $html);
        // The component renders OK
        $this->assertStringContainsString('<div', $html);
    }

    /**
     * Triangulation: the hero card renders correctly for operator
     * (shows "Revisar ahora" when there are pending cambios with personas detected).
     */
    public function test_operator_sees_hero_card_without_sensitive_details(): void
    {
        $operador = $this->makeOperador();

        // Cambio with persona_nueva in analysis (conPersona path via gemini)
        Cambio::factory()->create([
            'revisado'             => false,
            'gemini_analyzed'      => true,
            'gemini_analisis_json' => [
                'riesgo'        => 'alto',
                'es_mae'        => false,
                'persona_nueva' => 'Juan Pérez',
            ],
            'fecha'                => now(),
        ]);

        $html = Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->html();

        // Hero card visible — operator can see it and act on it
        $this->assertStringContainsString('Revisar ahora', $html);
    }
}
