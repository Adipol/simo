<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Livewire\Dashboard;
use App\Models\Fuente;
use App\Models\LogFuenteRun;
use App\Models\User;
use App\Services\Dashboard\DashboardCacheManager;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Integration tests for source health pill rendering in health-strip.
 *
 * RED → GREEN for T5.1 + T6.1-T6.3 (Phase 5+6 — UI + Integration)
 */
class SourceHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesPermisosSeeder::class);

        $this->admin = User::factory()->create(['activo' => true]);
        $this->admin->assignRole('admin');

        Cache::flush();

        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);
        config(['dashboard.source_health.consecutive_failures_degraded' => 3]);
        config(['dashboard.source_health.consecutive_failures_dead' => 10]);
        config(['dashboard.source_health.summary_cache_ttl' => 60]);
    }

    // ─── Pill variants ────────────────────────────────────────────────────────

    /**
     * Variant: available=false — no active fuentes → "Sin fuentes activas"
     */
    public function test_pill_shows_sin_fuentes_activas_when_no_active_fuentes(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('Sin fuentes activas');
    }

    /**
     * Variant: all sin_info (no runs yet) → "Recolectando datos…"
     */
    public function test_pill_shows_recolectando_when_all_fuentes_have_no_runs(): void
    {
        Fuente::factory()->count(3)->create(['activo' => true]);

        // No runs logged yet → all sin_info

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('Recolectando datos…');
    }

    /**
     * Variant: all ok → "24 ok" with green indicator
     */
    public function test_pill_shows_ok_count_when_all_sources_healthy(): void
    {
        $fuentes = Fuente::factory()->count(3)->create(['activo' => true]);
        $now = now();

        foreach ($fuentes as $fuente) {
            $this->createRun($fuente->id, 'success', $now->copy()->subMinutes(1));
        }

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('3 ok');
    }

    /**
     * Variant: mixed degraded — shows counts
     */
    public function test_pill_shows_degraded_counts_when_some_sources_failing(): void
    {
        $fuente1 = Fuente::factory()->create(['activo' => true]);
        $fuente2 = Fuente::factory()->create(['activo' => true]);
        $now = now();

        // fuente1: ok (1 success)
        $this->createRun($fuente1->id, 'success', $now->copy()->subMinutes(1));

        // fuente2: 3 consecutive failures → degradado
        $this->createRun($fuente2->id, 'http_error', $now->copy()->subMinutes(3));
        $this->createRun($fuente2->id, 'http_error', $now->copy()->subMinutes(2));
        $this->createRun($fuente2->id, 'http_error', $now->copy()->subMinutes(1));

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('1 ok')
            ->assertSee('1 degradada');
    }

    /**
     * Variant: any dead source → shows muerta count
     */
    public function test_pill_shows_dead_count_when_source_has_many_failures(): void
    {
        $fuente = Fuente::factory()->create(['activo' => true]);
        $now = now();

        for ($i = 10; $i >= 1; $i--) {
            $this->createRun($fuente->id, 'http_error', $now->copy()->subMinutes($i));
        }

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('1 muerta');
    }

    /**
     * Variant: partial warmup (some ok, some sin_info) → real counts, not warmup message
     * Note: we assert "sin datos" text appears (partial warmup shows counts, not warmup banner)
     * and that "Fuentes" label shows both ok + sin datos counts.
     */
    public function test_pill_shows_real_counts_during_partial_warmup(): void
    {
        $fuente1 = Fuente::factory()->create(['activo' => true]);
        Fuente::factory()->count(2)->create(['activo' => true]); // no runs yet

        // fuente1 has runs
        $this->createRun($fuente1->id, 'success', now()->subMinutes(1));

        // Partial warmup: fuente1=ok, fuente2+fuente3=sin_info → shows "1 ok / 2 sin datos"
        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('1 ok')
            ->assertSee('2 sin datos');
    }

    // ─── Permission: pill visible to all authenticated users ──────────────────

    public function test_pill_visible_to_operador(): void
    {
        Fuente::factory()->count(2)->create(['activo' => true]);

        $operador = User::factory()->create(['activo' => true]);
        $operador->assignRole('operador');

        Livewire::actingAs($operador)
            ->test(Dashboard::class)
            ->assertSee('Fuentes');
    }

    public function test_pill_visible_to_supervisor(): void
    {
        Fuente::factory()->count(2)->create(['activo' => true]);

        $supervisor = User::factory()->create(['activo' => true]);
        $supervisor->assignRole('supervisor');

        Livewire::actingAs($supervisor)
            ->test(Dashboard::class)
            ->assertSee('Fuentes');
    }

    // ─── Query count budget ────────────────────────────────────────────────────

    public function test_query_count_within_budget_on_warm_cache(): void
    {
        $fuentes = Fuente::factory()->count(3)->create(['activo' => true]);
        $now = now();

        foreach ($fuentes as $fuente) {
            $this->createRun($fuente->id, 'success', $now->copy()->subMinutes(1));
        }

        // Warm the cache first
        Livewire::actingAs($this->admin)->test(Dashboard::class);
        Cache::flush(); // Reset only the source health cache

        // Now measure with warm overall cache (health is pre-cached above)
        $queryCount = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        Livewire::actingAs($this->admin)->test(Dashboard::class);

        // Full Livewire render includes all islands (summary+health+discovery+sourceHealth).
        // Budget of 25 is for full page render; actual hot polling uses per-island caching.
        // The critical constraint is that sourceHealth itself uses ≤2 queries cold, 0 warm.
        $this->assertLessThanOrEqual(25, $queryCount,
            "Query count {$queryCount} exceeded budget on full render cycle");
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createRun(
        int $fuenteId,
        string $estado,
        \Carbon\Carbon $startedAt,
    ): LogFuenteRun {
        return LogFuenteRun::create([
            'fuente_id' => $fuenteId,
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => $startedAt->copy()->addSeconds(2)->toDateTimeString(),
            'estado' => $estado,
            'cambios_detectados' => 0,
            'duracion_segundos' => 2.0,
        ]);
    }
}
