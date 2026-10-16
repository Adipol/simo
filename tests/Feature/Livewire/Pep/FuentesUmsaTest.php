<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Pep;

use App\Livewire\Pep\Fuentes;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FuentesUmsaTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_y_reabre_el_adaptador_de_autoridades_umsa(): void
    {
        $component = Livewire::test(Fuentes::class)
            ->call('abrirModal')
            ->assertSee('UMSA Rectorado (cargo y persona)')
            ->set('url', 'https://www.umsa.bo/web/guest/rectorado')
            ->set('nombre', 'Rectorado UMSA')
            ->set('pais', 'BO')
            ->set('organismo', 'Universidad Mayor de San Andrés')
            ->set('nivel', 'nacional')
            ->set('tipo', 'html')
            ->set('activo', true)
            ->set('selector_css', '')
            ->set('autoridades_extractor', 'umsa_rectorado_profile')
            ->set('analizar_imagenes', false)
            ->call('guardar')
            ->assertHasNoErrors();

        $fuente = Fuente::query()->sole();

        $this->assertSame('umsa_rectorado_profile', $fuente->autoridades_extractor);

        $component
            ->call('abrirModal', $fuente->id)
            ->assertSet('autoridades_extractor', 'umsa_rectorado_profile');
    }

    public function test_conserva_la_opcion_divi_al_reabrir_una_fuente(): void
    {
        $fuente = Fuente::create([
            'url' => 'https://example.test/autoridades',
            'nombre' => 'Fuente Divi',
            'pais' => 'BO',
            'organismo' => 'Organismo de prueba',
            'nivel' => 'nacional',
            'tipo' => 'html',
            'activo' => true,
            'autoridades_extractor' => 'divi_blurb',
            'analizar_imagenes' => false,
        ]);

        Livewire::test(Fuentes::class)
            ->call('abrirModal', $fuente->id)
            ->assertSet('autoridades_extractor', 'divi_blurb')
            ->assertSee('Divi blurb (cargo y persona)');
    }

    public function test_rechaza_un_identificador_de_adaptador_desconocido(): void
    {
        Livewire::test(Fuentes::class)
            ->call('abrirModal')
            ->set('url', 'https://example.test/autoridades')
            ->set('nivel', 'nacional')
            ->set('tipo', 'html')
            ->set('autoridades_extractor', 'adaptador_desconocido')
            ->call('guardar')
            ->assertHasErrors(['autoridades_extractor' => 'in']);

        $this->assertDatabaseCount('fuentes', 0);
    }
}
