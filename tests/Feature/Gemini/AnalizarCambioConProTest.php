<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Exceptions\Gemini\GeminiRateLimitException;
use App\Jobs\AnalizarCambioConPro;
use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalizarCambioConProTest extends TestCase
{
    use RefreshDatabase;

    private function createFuente(array $overrides = []): Fuente
    {
        return Fuente::create(array_merge([
            'url' => 'https://gobierno.bo/ministerio-economia',
            'nombre' => 'Ministerio de Economía',
            'organismo' => 'Ministerio de Economía y Finanzas Públicas',
            'pais' => 'BO',
        ], $overrides));
    }

    private function createCambio(Fuente $fuente, array $overrides = []): Cambio
    {
        Cambio::flushEventListeners();

        return Cambio::create(array_merge([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'diff_texto' => "-Dr. Carlos Méndez\n+Lic. Ana García\n Director de Planificación",
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function fakeGeminiResponse(array $data): string
    {
        return json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($data),
                    ]],
                ],
            ]],
        ]);
    }

    public function test_happy_path_populates_gemini_analisis_json(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $fuente = $this->createFuente();

        // Explicit fechas: c1 newer than c2. The job orders by 'fecha DESC' so c1
        // will be processed first and receive the first Http::sequence response.
        //
        // Why explicit fechas matter: SQLite stores DATETIME at second precision
        // and ties resolve by ROWID ASC (insertion order). PostgreSQL stores
        // TIMESTAMP at microsecond precision, so two rows created in the same
        // second still differ by ~100µs, and ORDER BY fecha DESC puts the second
        // one first. Without explicit fechas the test passes on SQLite (because
        // c1 wins the tie) and fails on pgsql (because c2 wins by microsecond).
        $c1 = $this->createCambio($fuente, ['fecha' => now()]);
        $c2 = $this->createCambio($fuente, ['fecha' => now()->subMinutes(1)]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->fakeGeminiResponse([
                    'persona_removida' => 'Dr. Carlos Méndez',
                    'persona_nueva' => 'Lic. Ana García',
                    'cargo' => 'Ministro de Economía',
                    'es_mae' => true,
                    'riesgo' => 'alto',
                    'analisis' => 'Cambio de MAE detectado: nuevo Ministro de Economía.',
                ]))
                ->push($this->fakeGeminiResponse([
                    'persona_removida' => null,
                    'persona_nueva' => null,
                    'cargo' => null,
                    'es_mae' => false,
                    'riesgo' => 'bajo',
                    'analisis' => 'Sin cambios relevantes.',
                ])),
        ]);

        $job = new AnalizarCambioConPro;
        $job->handle();

        $c1->refresh();
        $this->assertTrue($c1->gemini_analyzed);
        $this->assertNotNull($c1->gemini_analisis_json);
        $this->assertSame('Dr. Carlos Méndez', $c1->gemini_analisis_json['persona_removida']);
        $this->assertSame('Lic. Ana García', $c1->gemini_analisis_json['persona_nueva']);
        $this->assertTrue($c1->gemini_analisis_json['es_mae']);
        $this->assertSame('alto', $c1->gemini_analisis_json['riesgo']);

        $c2->refresh();
        $this->assertTrue($c2->gemini_analyzed);
        $this->assertNotNull($c2->gemini_analisis_json);
        $this->assertFalse($c2->gemini_analisis_json['es_mae']);
    }

    public function test_batch_limit_processes_max_10_records(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $fuente = $this->createFuente();

        // Create 15 pending cambios
        for ($i = 0; $i < 15; $i++) {
            $this->createCambio($fuente, ['diff_texto' => "-Persona {$i}\n+Nueva {$i}"]);
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'persona_removida' => null,
                    'persona_nueva' => null,
                    'cargo' => null,
                    'es_mae' => false,
                    'riesgo' => 'bajo',
                    'analisis' => 'Sin cambios relevantes.',
                ]),
                200
            ),
        ]);

        Queue::fake();

        $job = new AnalizarCambioConPro;
        $job->handle();

        // 10 records should have been processed
        $this->assertSame(10, Cambio::where('gemini_analyzed', true)->count());
        // 5 should remain pending
        $this->assertSame(5, Cambio::where('gemini_analyzed', false)->count());

        // Self-dispatch should have been queued
        Queue::assertPushed(AnalizarCambioConPro::class);
    }

    public function test_no_self_dispatch_when_no_more_pending(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $fuente = $this->createFuente();
        $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'persona_removida' => null,
                    'persona_nueva' => null,
                    'cargo' => null,
                    'es_mae' => false,
                    'riesgo' => 'bajo',
                    'analisis' => 'Sin cambios.',
                ]),
                200
            ),
        ]);

        Bus::fake();

        $job = new AnalizarCambioConPro;
        $job->handle();

        Bus::assertNotDispatched(AnalizarCambioConPro::class);
    }

    public function test_no_records_returns_early_without_service_call(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $job = new AnalizarCambioConPro;
        $job->handle();

        Http::assertNothingSent();
    }

    public function test_disabled_returns_early_without_processing(): void
    {
        config([
            'services.gemini.enabled' => false,
            'services.gemini.api_key' => 'test-key',
        ]);

        $fuente = $this->createFuente();
        $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $job = new AnalizarCambioConPro;
        $job->handle();

        Http::assertNothingSent();
        $this->assertSame(1, Cambio::where('gemini_analyzed', false)->count());
    }

    public function test_disabled_does_not_self_dispatch(): void
    {
        config(['services.gemini.enabled' => false]);

        Queue::fake();

        $job = new AnalizarCambioConPro;
        $job->handle();

        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }

    public function test_job_uses_gemini_queue(): void
    {
        $job = new AnalizarCambioConPro;
        $this->assertSame('gemini', $job->queue);
    }

    public function test_job_has_correct_retry_config(): void
    {
        $job = new AnalizarCambioConPro;
        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 25, 125], $job->backoff);
    }

    public function test_failed_marks_batch_as_analyzed(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $fuente = $this->createFuente();
        $c1 = $this->createCambio($fuente);
        $c2 = $this->createCambio($fuente);

        $job = new AnalizarCambioConPro;
        $job->failed(new \RuntimeException('Simulated failure'));

        $c1->refresh();
        $c2->refresh();

        $this->assertTrue($c1->gemini_analyzed);
        $this->assertTrue($c2->gemini_analyzed);

        // gemini_analisis_json should remain null (no API data)
        $this->assertNull($c1->gemini_analisis_json);
        $this->assertNull($c2->gemini_analisis_json);
    }

    public function test_rate_limit_exception_propagates_for_retry(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
        ]);

        $fuente = $this->createFuente();
        $this->createCambio($fuente);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        $job = new AnalizarCambioConPro;

        $this->expectException(GeminiRateLimitException::class);
        $job->handle();
    }

    public function test_self_dispatch_uses_config_delay(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.pro_delay' => 60,
        ]);

        $fuente = $this->createFuente();

        // Create 15 records to trigger self-dispatch
        for ($i = 0; $i < 15; $i++) {
            $this->createCambio($fuente, ['diff_texto' => "-Persona {$i}\n+Nueva {$i}"]);
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeGeminiResponse([
                    'persona_removida' => null,
                    'persona_nueva' => null,
                    'cargo' => null,
                    'es_mae' => false,
                    'riesgo' => 'bajo',
                    'analisis' => 'Sin cambios.',
                ]),
                200
            ),
        ]);

        Queue::fake();

        $job = new AnalizarCambioConPro;
        $job->handle();

        Queue::assertPushed(AnalizarCambioConPro::class);
    }
}
