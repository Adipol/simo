<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Fuente;

use App\Models\Fuente;
use App\Services\Fuente\FuenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FuenteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FuenteService
    {
        return new FuenteService;
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://example.gob.bo/autoridades',
            'nombre' => 'Test Fuente',
            'organismo' => 'Ministerio Test',
            'pais' => 'BO',
            'nivel' => 'nacional',
            'tipo' => 'html',
            'activo' => true,
            'selector_css' => '',
            'analizar_imagenes' => false,
        ], $overrides);
    }

    public function test_crear_fuente_persiste_datos_basicos(): void
    {
        $fuente = $this->service()->crear($this->validData());

        $this->assertInstanceOf(Fuente::class, $fuente);
        $this->assertSame('https://example.gob.bo/autoridades', $fuente->url);
        $this->assertSame('Test Fuente', $fuente->nombre);
        $this->assertSame('Ministerio Test', $fuente->organismo);
        $this->assertSame('BO', $fuente->pais);
        $this->assertTrue($fuente->activo);
    }

    public function test_crear_fuente_default_analizar_imagenes_false(): void
    {
        $data = $this->validData();
        unset($data['analizar_imagenes']);

        $fuente = $this->service()->crear($data);

        $this->assertFalse($fuente->analizar_imagenes);
    }

    public function test_crear_fuente_con_analizar_imagenes_true(): void
    {
        $data = $this->validData(['analizar_imagenes' => true]);

        $fuente = $this->service()->crear($data);

        $this->assertTrue($fuente->analizar_imagenes);
    }

    public function test_actualizar_fuente_modifica_solo_campos_pasados(): void
    {
        $fuente = Fuente::create($this->validData(['nombre' => 'Original']));

        $actualizada = $this->service()->actualizar($fuente->id, [
            'url' => $fuente->url,
            'nombre' => 'Modificada',
            'organismo' => $fuente->organismo,
            'pais' => $fuente->pais,
            'nivel' => $fuente->nivel,
            'tipo' => $fuente->tipo,
            'activo' => $fuente->activo,
            'selector_css' => $fuente->selector_css ?? '',
            'analizar_imagenes' => $fuente->analizar_imagenes,
        ]);

        $this->assertSame('Modificada', $actualizada->nombre);
        $this->assertSame($fuente->url, $actualizada->url);
    }

    public function test_actualizar_fuente_toggle_analizar_imagenes_off_a_on(): void
    {
        $fuente = Fuente::create($this->validData(['analizar_imagenes' => false]));

        $actualizada = $this->service()->actualizar($fuente->id, $this->validData([
            'analizar_imagenes' => true,
        ]));

        $this->assertTrue($actualizada->analizar_imagenes);
    }

    public function test_actualizar_fuente_toggle_analizar_imagenes_on_a_off(): void
    {
        $fuente = Fuente::create($this->validData(['analizar_imagenes' => true]));

        $actualizada = $this->service()->actualizar($fuente->id, $this->validData([
            'analizar_imagenes' => false,
        ]));

        $this->assertFalse($actualizada->analizar_imagenes);
    }

    public function test_toggle_activo_invierte_estado_actual(): void
    {
        $fuente = Fuente::create($this->validData(['activo' => true]));

        $resultado = $this->service()->toggleActivo($fuente->id);

        $this->assertFalse($resultado->activo);

        // Segundo toggle vuelve a true
        $resultado2 = $this->service()->toggleActivo($fuente->id);
        $this->assertTrue($resultado2->activo);
    }

    public function test_paginar_sin_filtros_devuelve_todas_las_fuentes(): void
    {
        Fuente::create($this->validData(['url' => 'https://a.bo', 'nombre' => 'A']));
        Fuente::create($this->validData(['url' => 'https://b.bo', 'nombre' => 'B']));

        $result = $this->service()->paginar([]);

        $this->assertSame(2, $result->total());
    }

    public function test_paginar_con_busqueda_filtra_por_nombre(): void
    {
        Fuente::create($this->validData(['url' => 'https://a.bo', 'nombre' => 'Ministerio Hacienda']));
        Fuente::create($this->validData(['url' => 'https://b.bo', 'nombre' => 'Banco Central']));

        $result = $this->service()->paginar(['busqueda' => 'Hacienda']);

        $this->assertSame(1, $result->total());
        $this->assertSame('Ministerio Hacienda', $result->items()[0]->nombre);
    }

    public function test_paginar_con_filtro_activo_devuelve_solo_activas(): void
    {
        Fuente::create($this->validData(['url' => 'https://a.bo', 'nombre' => 'Activa', 'activo' => true]));
        Fuente::create($this->validData(['url' => 'https://b.bo', 'nombre' => 'Inactiva', 'activo' => false]));

        $result = $this->service()->paginar(['activo' => true]);

        $this->assertSame(1, $result->total());
        $this->assertSame('Activa', $result->items()[0]->nombre);
    }

    public function test_paginar_con_filtro_nivel_filtra_correctamente(): void
    {
        Fuente::create($this->validData(['url' => 'https://a.bo', 'nivel' => 'nacional']));
        Fuente::create($this->validData(['url' => 'https://b.bo', 'nivel' => 'municipal']));

        $result = $this->service()->paginar(['nivel' => 'municipal']);

        $this->assertSame(1, $result->total());
    }
}
