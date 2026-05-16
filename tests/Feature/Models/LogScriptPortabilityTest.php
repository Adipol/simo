<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\ConfigScript;
use App\Models\LogScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
}
