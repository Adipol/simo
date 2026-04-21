<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ResultadosConfirmarPepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.gemini.enabled' => false]);
        $this->seed(RolesPermisosSeeder::class);
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function createOperador(): User
    {
        $user = User::factory()->create();
        $user->assignRole('operador');

        return $user;
    }

    private function createResultado(array $attrs = []): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://test.com/article-'.uniqid(),
            'keyword' => 'test keyword pep',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => false,
            'gemini_is_pep' => null,
        ], $attrs));
    }

    // ─── 1. Default filtroGemini ──────────────────────────────────────────────

    public function test_filtro_gemini_defaults_to_empty_string(): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->assertSet('filtroGemini', '');
    }

    // ─── 2. Button visibility ─────────────────────────────────────────────────

    public function test_confirmar_pep_button_oculto_cuando_ya_es_pep(): void
    {
        $admin = $this->createAdmin();
        $this->createResultado(['gemini_is_pep' => true, 'gemini_analyzed' => true, 'keyword' => 'ya-es-pep']);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->assertDontSee('Confirmar PEP');
    }

    public function test_confirmar_pep_button_visible_cuando_no_es_pep(): void
    {
        $admin = $this->createAdmin();
        $this->createResultado(['gemini_is_pep' => false, 'gemini_analyzed' => true, 'keyword' => 'no-es-pep-todavia']);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->assertSee('Confirmar PEP');
    }

    public function test_confirmar_pep_button_oculto_para_operador_sin_permiso(): void
    {
        $operador = $this->createOperador();
        $this->createResultado(['gemini_is_pep' => false, 'gemini_analyzed' => true]);

        Livewire::actingAs($operador)
            ->test(Resultados::class)
            ->assertDontSee('Confirmar PEP');
    }

    // ─── 3. Modal open ────────────────────────────────────────────────────────

    public function test_abrir_confirmar_pep_modal_sets_id(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->assertSet('confirmarPepModalId', $resultado->id)
            ->assertSet('pepNombre', '')
            ->assertSet('pepCargo', '')
            ->assertSet('pepEvento', '');
    }

    // ─── 4. Validation ────────────────────────────────────────────────────────

    public function test_confirmar_pep_falla_sin_nombre(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->set('pepNombre', '')
            ->call('confirmarPep')
            ->assertHasErrors(['pepNombre' => 'required']);

        $this->assertDatabaseCount('resultado_personas', 0);
    }

    public function test_confirmar_pep_falla_con_nombre_mayor_a_200_chars(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->set('pepNombre', str_repeat('a', 201))
            ->call('confirmarPep')
            ->assertHasErrors(['pepNombre' => 'max']);

        $this->assertDatabaseCount('resultado_personas', 0);
    }

    public function test_confirmar_pep_falla_con_evento_invalido(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->set('pepNombre', 'Juan Pérez')
            ->set('pepEvento', 'invalido')
            ->call('confirmarPep')
            ->assertHasErrors(['pepEvento']);

        $this->assertDatabaseCount('resultado_personas', 0);
    }

    // ─── 5. Happy path — transaction creates all records ─────────────────────

    public function test_confirmar_pep_crea_persona_resultado_y_feedback(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado([
            'gemini_is_pep' => false,
            'gemini_analyzed' => true,
            'gemini_categoria' => 'No relevante',
            'gemini_confianza' => 30,
        ]);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->set('pepNombre', 'Juan Pérez')
            ->set('pepCargo', 'Senador')
            ->set('pepEvento', 'designacion')
            ->call('confirmarPep')
            ->assertHasNoErrors();

        // ResultadoPersona created
        $this->assertDatabaseHas('resultado_personas', [
            'resultado_scraping_id' => $resultado->id,
            'nombre' => 'Juan Pérez',
            'cargo' => 'Senador',
            'categoria' => 'PEP',
            'entidad_tipo' => 'desconocido',
            'confianza' => 100,
            'threshold_passed' => true,
            'evento' => 'designacion',
        ]);

        // ResultadoScraping updated
        $resultado->refresh();
        $this->assertTrue((bool) $resultado->gemini_is_pep);
        $this->assertTrue((bool) $resultado->gemini_analyzed);
        $this->assertSame('PEP', $resultado->gemini_categoria);
        $this->assertSame('Juan Pérez', $resultado->gemini_nombre);
        $this->assertSame('Senador', $resultado->gemini_cargo);

        // ClasificacionFeedback created
        $this->assertDatabaseHas('clasificaciones_feedback', [
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $admin->id,
            'tipo' => 'correcto',
            'corregido_is_pep' => true,
            'corregido_categoria' => 'PEP',
            'corregido_nombre' => 'Juan Pérez',
            'corregido_cargo' => 'Senador',
        ]);
    }

    public function test_confirmar_pep_normaliza_nombre(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->set('pepNombre', 'DR. JUAN PÉREZ SOTO')
            ->call('confirmarPep')
            ->assertHasNoErrors();

        $persona = ResultadoPersona::where('resultado_scraping_id', $resultado->id)->first();
        $this->assertNotNull($persona);
        $this->assertNotSame('DR. JUAN PÉREZ SOTO', $persona->nombre_normalizado);
        // Normalized removes "DR." title and applies title case
        $this->assertStringContainsString('Juan', $persona->nombre_normalizado);
    }

    public function test_confirmar_pep_cierra_modal_tras_exito(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->set('pepNombre', 'María García')
            ->call('confirmarPep')
            ->assertSet('confirmarPepModalId', null);
    }

    // ─── 6. Requires permission ───────────────────────────────────────────────

    public function test_confirmar_pep_requiere_permiso_dar_feedback(): void
    {
        $operador = $this->createOperador();
        $resultado = $this->createResultado();

        Livewire::actingAs($operador)
            ->test(Resultados::class)
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->assertForbidden();
    }

    // ─── 7. Result appears in PEP filter after confirm ───────────────────────

    public function test_resultado_aparece_en_filtro_pep_tras_confirmar(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado([
            'keyword' => 'keyword-para-pep',
            'gemini_is_pep' => false,
            'gemini_analyzed' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('filtroGemini', 'pep')
            ->assertDontSee('keyword-para-pep')
            ->call('abrirConfirmarPepModal', $resultado->id)
            ->set('pepNombre', 'Carlos López')
            ->call('confirmarPep')
            ->assertSee('keyword-para-pep');
    }
}
