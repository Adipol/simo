<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Fuente;
use App\Models\LogFuenteRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class WidenLogFuenteRunEstadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_preserves_existing_runs_and_stores_all_current_states(): void
    {
        $migration = $this->migration();
        $migration->down();
        $source = Fuente::factory()->create();
        $existing = LogFuenteRun::create([
            'fuente_id' => $source->id, 'started_at' => now(),
            'estado' => 'success', 'cambios_detectados' => 3,
        ]);
        $before = $existing->fresh()->getAttributes();

        $migration->up();

        $this->assertSame($before, $existing->fresh()->getAttributes());
        foreach (LogFuenteRun::VALID_ESTADOS as $state) {
            $run = LogFuenteRun::create([
                'fuente_id' => $source->id, 'started_at' => now(), 'estado' => $state,
            ]);
            $this->assertSame($state, $run->fresh()->estado);
        }
        $this->assertSame(count(LogFuenteRun::VALID_ESTADOS) + 1, LogFuenteRun::count());
    }

    public function test_down_refuses_to_truncate_recorded_states_without_changing_rows(): void
    {
        $source = Fuente::factory()->create();
        foreach (['success', 'authority_review_pending'] as $state) {
            LogFuenteRun::create([
                'fuente_id' => $source->id, 'started_at' => now(), 'estado' => $state,
            ]);
        }
        $before = LogFuenteRun::orderBy('id')->get()->toArray();

        try {
            $this->migration()->down();
            $this->fail('Rollback accepted a state that would be truncated.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot narrow log_fuente_runs.estado while recorded states exceed 20 characters.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($before, LogFuenteRun::orderBy('id')->get()->toArray());
        $this->assertSame(2, LogFuenteRun::count());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_16_000001_widen_log_fuente_runs_estado.php');
    }
}
