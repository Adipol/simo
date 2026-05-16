<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\ConfigScript;
use App\Models\LogScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogScriptPortabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /**
     * limpiarHuerfanos marca como interrumpido y calcula duracion_segundos
     * con tolerancia de ±2 seg para absorber precision de julianday en sqlite.
     */
    public function test_limpiar_huerfanos_marks_interrupted_on_current_driver(): void
    {
        $now = Carbon::now();
        $inicio = $now->copy()->subSeconds(120);

        // ConfigScript needed by limpiarHuerfanos to determine timeout
        ConfigScript::create([
            'script' => 'scraper',
            'timeout_minutos' => 1, // 1 min → row de hace 120s supera el timeout
        ]);

        LogScript::factory()->create([
            'script' => 'scraper',
            'estado' => 'iniciado',
            'inicio' => $inicio,
            'fin' => null,
            'duracion_segundos' => null,
        ]);

        $affected = LogScript::limpiarHuerfanos('scraper');

        $this->assertSame(1, $affected);

        $row = LogScript::first();

        $this->assertNotNull($row->fin, 'fin debe quedar registrado');
        $this->assertSame('interrumpido', $row->estado);

        $duracion = (int) $row->duracion_segundos;
        $this->assertGreaterThanOrEqual(118, $duracion, "duracion_segundos debe ser >= 118, fue {$duracion}");
        $this->assertLessThanOrEqual(122, $duracion, "duracion_segundos debe ser <= 122, fue {$duracion}");
    }

    /**
     * limpiarHuerfanos NO toca filas que ya tienen fin registrado.
     */
    public function test_limpiar_huerfanos_does_not_touch_already_finished_rows(): void
    {
        $now = Carbon::now();
        $inicio = $now->copy()->subSeconds(200);
        $fin = $now->copy()->subSeconds(80);

        ConfigScript::create([
            'script' => 'scraper',
            'timeout_minutos' => 1,
        ]);

        LogScript::factory()->create([
            'script' => 'scraper',
            'estado' => 'completado',
            'inicio' => $inicio,
            'fin' => $fin,
            'duracion_segundos' => 120,
        ]);

        $affected = LogScript::limpiarHuerfanos('scraper');

        $this->assertSame(0, $affected, 'No debe tocar filas ya finalizadas');

        $row = LogScript::first();
        $this->assertSame('completado', $row->estado);
        $this->assertSame('120.00', $row->duracion_segundos);
    }

    /**
     * limpiarHuerfanos NO toca filas en estado 'iniciado' que esten dentro del timeout.
     */
    public function test_limpiar_huerfanos_does_not_touch_rows_within_timeout(): void
    {
        ConfigScript::create([
            'script' => 'scraper',
            'timeout_minutos' => 120, // 2h de timeout
        ]);

        // Row started 30 seconds ago — well within 2h timeout
        LogScript::factory()->create([
            'script' => 'scraper',
            'estado' => 'iniciado',
            'inicio' => Carbon::now()->subSeconds(30),
            'fin' => null,
            'duracion_segundos' => null,
        ]);

        $affected = LogScript::limpiarHuerfanos('scraper');

        $this->assertSame(0, $affected, 'No debe tocar filas dentro del timeout');

        $row = LogScript::first();
        $this->assertSame('iniciado', $row->estado);
        $this->assertNull($row->fin);
    }

    /**
     * Regression test for the CI post-incident bug (2026-05-16):
     * When the pgsql cluster session timezone differs from config('app.timezone')
     * (e.g. postgres:17 container defaults to UTC, app uses America/La_Paz),
     * the naive `NOW() - inicio` subtraction produces a 4-hour drift (14400 sec).
     *
     * This test forces session_tz=UTC on the pgsql connection to reproduce the CI
     * environment on ANY developer machine, regardless of the local cluster timezone.
     *
     * NOTE: This test is pgsql-only because it tests pgsql-specific session-timezone
     * semantics (`SET TIME ZONE`). This is NOT a "driver-portability skip" (which REQ-5
     * prohibits) — it is a skip because the scenario (pgsql session_tz mismatch) literally
     * does not exist on sqlite. The distinction is: we skip because sqlite lacks the
     * FEATURE being tested, not because our code doesn't work on sqlite.
     */
    public function test_limpiar_huerfanos_works_when_pgsql_session_timezone_differs_from_app_timezone(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgsql-only: simulates cluster session timezone different from app timezone');
        }

        // Force session timezone to UTC — this is what the postgres:17 CI container does.
        // On a developer machine whose cluster runs with America/La_Paz, this SET command
        // creates the same mismatch that CI experiences, reproducing the failure locally.
        DB::statement("SET TIME ZONE 'UTC'");

        $now = Carbon::now();
        $inicio = $now->copy()->subSeconds(120);

        ConfigScript::create([
            'script' => 'scraper',
            'timeout_minutos' => 1,
        ]);

        LogScript::factory()->create([
            'script' => 'scraper',
            'estado' => 'iniciado',
            'inicio' => $inicio,
            'fin' => null,
            'duracion_segundos' => null,
        ]);

        $affected = LogScript::limpiarHuerfanos('scraper');

        $this->assertSame(1, $affected);

        $row = LogScript::first();
        $duracion = (int) $row->duracion_segundos;

        $this->assertGreaterThanOrEqual(118, $duracion, "duracion_segundos debe ser >= 118, fue {$duracion}");
        $this->assertLessThanOrEqual(122, $duracion, "duracion_segundos debe ser <= 122, fue {$duracion}");
    }

    /**
     * epochSecondsSince lanza RuntimeException cuando el driver no es pgsql ni sqlite.
     *
     * Coverage test: the default branch of the match in epochSecondsSince() MUST throw.
     * The helper code is correct; this test closes the REQ-1 "unknown driver" spec scenario.
     *
     * NOTE: Because epochSecondsSince is private static, we trigger it via limpiarHuerfanos.
     * We mock DB::getDriverName() to return 'mysql' so the match hits the default arm.
     * The RuntimeException fires before any query reaches the DB, so no DB state is needed.
     */
    public function test_it_throws_on_unknown_driver_for_epoch_seconds_since(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mysql/');

        // Stub the driver name so the match hits the default → throw branch.
        DB::shouldReceive('getDriverName')->andReturn('mysql');

        // We also need ConfigScript lookup to not explode before we reach the helper;
        // DB::shouldReceive already stubs the facade, so we add a pass-through for the
        // select query that ConfigScript::where('script')->value() issues.
        DB::shouldReceive('select')->andReturn([]);

        // limpiarHuerfanos calls epochSecondsSince internally; the exception fires
        // when it tries to build the DB::raw() expression for duracion_segundos.
        LogScript::limpiarHuerfanos('scraper');
    }
}
