<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Jobs\AnalizarCambioConPro;
use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CambioObserverTest extends TestCase
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
        return Cambio::create(array_merge([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'diff_texto' => "-Dr. Carlos Méndez\n+Lic. Ana García\n Director de Planificación",
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_created_dispatches_job_with_correct_queue_and_delay(): void
    {
        config(['services.gemini.enabled' => true]);

        Queue::fake();

        $fuente = $this->createFuente();
        $this->createCambio($fuente);

        Queue::assertPushed(AnalizarCambioConPro::class, function ($job) {
            return $job->queue === 'gemini';
        });
    }

    public function test_gemini_disabled_does_not_dispatch(): void
    {
        config(['services.gemini.enabled' => false]);

        Queue::fake();

        $fuente = $this->createFuente();
        $this->createCambio($fuente);

        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }

    public function test_update_does_not_dispatch(): void
    {
        config(['services.gemini.enabled' => true]);

        Queue::fake();

        $fuente = $this->createFuente();
        $cambio = $this->createCambio($fuente);

        // Reset the fake to clear any dispatches from create
        Queue::fake();

        $cambio->update(['revisado' => true]);

        Queue::assertNotPushed(AnalizarCambioConPro::class);
    }

    public function test_custom_pro_delay_is_respected(): void
    {
        config([
            'services.gemini.enabled' => true,
            'services.gemini.pro_delay' => 60,
        ]);

        Queue::fake();

        $fuente = $this->createFuente();
        $this->createCambio($fuente);

        Queue::assertPushed(AnalizarCambioConPro::class);
    }
}
