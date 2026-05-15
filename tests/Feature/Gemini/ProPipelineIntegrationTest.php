<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Fuente $fuente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fuente = Fuente::create([
            'url' => 'https://example.com/gobierno',
            'nombre' => 'Gobierno Test',
            'pais' => 'BO',
            'organismo' => 'Ministerio de Hacienda',
            'activo' => true,
        ]);
    }

    private function createCambio(array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => $this->fuente->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'diff_texto' => '- Ministro Juan Pérez\n+ Ministra María López',
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_full_pro_pipeline_observer_dispatches_job_updates_db(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        // 1. Create cambio — observer is flushed
        $cambio = $this->createCambio();
        $this->assertFalse($cambio->gemini_analyzed);

        // 2. Fake HTTP with fixture
        $fixture = file_get_contents(base_path('tests/Fixtures/Gemini/pro_success.json'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(json_decode($fixture, true), 200),
        ]);

        // 3. Fake queue, dispatch, then run directly
        Queue::fake();
        \App\Jobs\AnalizarCambioConPro::dispatch();
        Queue::assertPushed(\App\Jobs\AnalizarCambioConPro::class);

        $job = new \App\Jobs\AnalizarCambioConPro;
        $job->handle();

        // 4. Assert gemini_analisis_json is populated
        $cambio->refresh();

        $this->assertTrue($cambio->gemini_analyzed);
        $this->assertNotNull($cambio->gemini_analisis_json);

        $analisis = $cambio->gemini_analisis_json;
        $this->assertIsArray($analisis);
        $this->assertSame('Carlos López', $analisis['persona_removida']);
        $this->assertSame('Ana García', $analisis['persona_nueva']);
        $this->assertSame('Ministro de Hacienda', $analisis['cargo']);
        $this->assertTrue($analisis['es_mae']);
        $this->assertSame('alto', $analisis['riesgo']);
        $this->assertSame('Cambio de MAE en cartera sensible de finanzas públicas', $analisis['analisis']);
    }

    public function test_pro_pipeline_detects_mae_via_where_raw(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $cambio = $this->createCambio();

        $fixture = file_get_contents(base_path('tests/Fixtures/Gemini/pro_success.json'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(json_decode($fixture, true), 200),
        ]);

        $job = new \App\Jobs\AnalizarCambioConPro;
        $job->handle();

        // Driver-aware JSON check: SQLite uses json_extract (legacy), pgsql uses ->>
        // and needs explicit boolean cast.
        $isPgsql = DB::getDriverName() === 'pgsql';
        $maeCount = Cambio::where('gemini_analyzed', true)
            ->whereRaw(
                $isPgsql
                    ? "(gemini_analisis_json->>'es_mae')::boolean = true"
                    : "json_extract(gemini_analisis_json, '$.es_mae') = 1"
            )
            ->count();

        $this->assertSame(1, $maeCount);
    }

    public function test_pro_pipeline_skips_already_analyzed(): void
    {
        config(['services.gemini.enabled' => true]);
        config(['services.gemini.api_key' => 'test-key-123']);

        $analyzed = $this->createCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['old' => 'data'],
        ]);

        $pending = $this->createCambio();

        $fixture = file_get_contents(base_path('tests/Fixtures/Gemini/pro_success.json'));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(json_decode($fixture, true), 200),
        ]);

        $job = new \App\Jobs\AnalizarCambioConPro;
        $job->handle();

        // Already analyzed is untouched
        $analyzed->refresh();
        $this->assertSame(['old' => 'data'], $analyzed->gemini_analisis_json);

        // Pending is now analyzed
        $pending->refresh();
        $this->assertTrue($pending->gemini_analyzed);
        $this->assertNotNull($pending->gemini_analisis_json);
    }
}
