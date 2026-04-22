<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Pep;

use App\Livewire\Pep\Cambios;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CambiosFiltroRiesgoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Fuente $fuente;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.enabled' => false]);
        $this->user = User::factory()->create(['activo' => true]);
        $this->fuente = Fuente::create([
            'organismo' => 'Test Organismo',
            'pais' => 'AR',
            'url' => 'https://test.example.com',
        ]);
    }

    private function makeCambio(array $overrides = []): Cambio
    {
        return Cambio::create(array_merge([
            'fuente_id' => $this->fuente->id,
            'fecha' => now(),
            'hash_anterior' => uniqid('h_', true),
            'hash_nuevo' => uniqid('h_', true),
            'lineas_quitadas' => 1,
            'lineas_nuevas' => 1,
            'gemini_analyzed' => false,
            'gemini_analisis_json' => null,
        ], $overrides));
    }

    public function test_filtro_riesgo_alto_shows_only_alto_records(): void
    {
        $alto = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Juan Pérez',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'es_mae' => true,
                'analisis' => 'Riesgo alto',
            ],
        ]);

        $medio = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Ana García',
                'persona_removida' => null,
                'riesgo' => 'medio',
                'es_mae' => false,
                'analisis' => 'Riesgo medio',
            ],
        ]);

        $bajo = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Riesgo bajo',
            ],
        ]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', '')  // ver todos para no interferir
            ->set('filtroRiesgo', 'alto')
            ->assertViewHas('cambios', function ($cambios) use ($alto, $medio, $bajo) {
                $ids = $cambios->pluck('id');

                return $ids->contains($alto->id)
                    && ! $ids->contains($medio->id)
                    && ! $ids->contains($bajo->id);
            });
    }

    public function test_filtro_riesgo_medio_shows_only_medio_records(): void
    {
        $alto = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Juan Pérez',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'es_mae' => true,
                'analisis' => 'Riesgo alto',
            ],
        ]);

        $medio = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Ana García',
                'persona_removida' => null,
                'riesgo' => 'medio',
                'es_mae' => false,
                'analisis' => 'Riesgo medio',
            ],
        ]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', '')
            ->set('filtroRiesgo', 'medio')
            ->assertViewHas('cambios', function ($cambios) use ($alto, $medio) {
                $ids = $cambios->pluck('id');

                return ! $ids->contains($alto->id)
                    && $ids->contains($medio->id);
            });
    }

    public function test_filtro_riesgo_vacio_shows_all_records_without_riesgo_filter(): void
    {
        $alto = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Juan Pérez',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'es_mae' => true,
                'analisis' => 'Riesgo alto',
            ],
        ]);

        $bajo = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Riesgo bajo',
            ],
        ]);

        // Con filtroConPersona='' y filtroRiesgo='' se muestran todos
        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', '')
            ->set('filtroRiesgo', '')
            ->assertViewHas('cambios', function ($cambios) use ($alto, $bajo) {
                $ids = $cambios->pluck('id');

                return $ids->contains($alto->id) && $ids->contains($bajo->id);
            });
    }

    public function test_changing_filtro_riesgo_resets_pagination(): void
    {
        // Crear > 20 records con persona para que la paginación funcione con el filtro por defecto
        for ($i = 0; $i < 25; $i++) {
            $this->makeCambio([
                'gemini_analyzed' => true,
                'gemini_analisis_json' => [
                    'persona_nueva' => "Persona {$i}",
                    'persona_removida' => null,
                    'riesgo' => 'alto',
                    'es_mae' => true,
                    'analisis' => "Cambio {$i}",
                ],
            ]);
        }

        $component = Livewire::actingAs($this->user)->test(Cambios::class);

        // Ir a página 2
        $component->call('gotoPage', 2);

        // Cambiar filtro de riesgo — paginators debe resetearse a página 1
        $component->set('filtroRiesgo', 'alto');

        // En Livewire 4 con WithPagination, paginators['page'] = 1 indica que está en primera página
        $component->assertSet('paginators', ['page' => 1]);
    }
}
