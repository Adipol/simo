<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Fuente;
use App\Models\LogFuenteRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RED → GREEN for LogFuenteRun model.
 * Tests: T2.1 (Phase 2 — Model)
 */
class LogFuenteRunTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fillable ─────────────────────────────────────────────────────────────

    public function test_fillable_includes_required_fields(): void
    {
        $model = new LogFuenteRun;

        $this->assertContains('fuente_id', $model->getFillable());
        $this->assertContains('started_at', $model->getFillable());
        $this->assertContains('finished_at', $model->getFillable());
        $this->assertContains('estado', $model->getFillable());
        $this->assertContains('http_status', $model->getFillable());
        $this->assertContains('cambios_detectados', $model->getFillable());
        $this->assertContains('error_mensaje', $model->getFillable());
        $this->assertContains('duracion_segundos', $model->getFillable());
    }

    // ─── Casts ────────────────────────────────────────────────────────────────

    public function test_started_at_is_cast_to_datetime(): void
    {
        $fuente = Fuente::factory()->create();

        $run = LogFuenteRun::create([
            'fuente_id' => $fuente->id,
            'started_at' => '2026-05-10 15:30:00',
            'estado' => 'success',
            'cambios_detectados' => 0,
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $run->started_at);
    }

    public function test_finished_at_is_cast_to_datetime_when_set(): void
    {
        $fuente = Fuente::factory()->create();

        $run = LogFuenteRun::create([
            'fuente_id' => $fuente->id,
            'started_at' => '2026-05-10 15:30:00',
            'finished_at' => '2026-05-10 15:30:05',
            'estado' => 'success',
            'cambios_detectados' => 0,
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $run->finished_at);
    }

    public function test_http_status_is_cast_to_integer(): void
    {
        $fuente = Fuente::factory()->create();

        $run = LogFuenteRun::create([
            'fuente_id' => $fuente->id,
            'started_at' => '2026-05-10 15:30:00',
            'estado' => 'http_error',
            'http_status' => '404',
            'cambios_detectados' => 0,
        ]);

        $this->assertIsInt($run->http_status);
        $this->assertSame(404, $run->http_status);
    }

    public function test_cambios_detectados_is_cast_to_integer(): void
    {
        $fuente = Fuente::factory()->create();

        $run = LogFuenteRun::create([
            'fuente_id' => $fuente->id,
            'started_at' => '2026-05-10 15:30:00',
            'estado' => 'success',
            'cambios_detectados' => '3',
        ]);

        $this->assertIsInt($run->cambios_detectados);
        $this->assertSame(3, $run->cambios_detectados);
    }

    public function test_duracion_segundos_is_cast_to_float(): void
    {
        $fuente = Fuente::factory()->create();

        $run = LogFuenteRun::create([
            'fuente_id' => $fuente->id,
            'started_at' => '2026-05-10 15:30:00',
            'estado' => 'success',
            'cambios_detectados' => 0,
            'duracion_segundos' => '2.5',
        ]);

        $this->assertIsFloat($run->duracion_segundos);
        $this->assertEqualsWithDelta(2.5, $run->duracion_segundos, 0.001);
    }

    // ─── Relationship ─────────────────────────────────────────────────────────

    public function test_fuente_relationship_returns_fuente_model(): void
    {
        $fuente = Fuente::factory()->create();

        $run = LogFuenteRun::create([
            'fuente_id' => $fuente->id,
            'started_at' => '2026-05-10 15:30:00',
            'estado' => 'success',
            'cambios_detectados' => 0,
        ]);

        $this->assertInstanceOf(Fuente::class, $run->fuente);
        $this->assertSame($fuente->id, $run->fuente->id);
    }

    // ─── No timestamps ────────────────────────────────────────────────────────

    public function test_model_has_no_auto_timestamps(): void
    {
        $model = new LogFuenteRun;

        $this->assertFalse($model->usesTimestamps());
    }

    // ─── CASCADE FK ───────────────────────────────────────────────────────────

    public function test_deleting_fuente_cascades_to_runs(): void
    {
        $fuente = Fuente::factory()->create();

        LogFuenteRun::create([
            'fuente_id' => $fuente->id,
            'started_at' => '2026-05-10 15:30:00',
            'estado' => 'success',
            'cambios_detectados' => 0,
        ]);

        $this->assertDatabaseCount('log_fuente_runs', 1);

        $fuente->delete();

        $this->assertDatabaseCount('log_fuente_runs', 0);
    }
}
