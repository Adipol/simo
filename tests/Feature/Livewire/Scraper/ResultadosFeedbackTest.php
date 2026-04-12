<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Enums\CategoriaCorreccion;
use App\Enums\TipoFeedback;
use App\Livewire\Scraper\Resultados;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ResultadosFeedbackTest extends TestCase
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

    private function createAnalyzedResultado(): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create([
            'url' => 'https://test.com/article-'.uniqid(),
            'keyword' => 'test keyword',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre' => 'Juan Perez',
            'gemini_cargo' => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo' => 'Es un PEP.',
        ]);
    }

    // ─── 6.1 Permission / Button Visibility ───────────────────────────────────

    public function test_operador_does_not_see_feedback_buttons(): void
    {
        $operador = $this->createOperador();
        $this->createAnalyzedResultado();

        Livewire::actingAs($operador)
            ->test(Resultados::class)
            ->assertDontSee('✓ Correcto')
            ->assertDontSee('✗ Incorrecto');
    }

    public function test_admin_sees_feedback_buttons_on_analyzed_rows(): void
    {
        $admin = $this->createAdmin();
        $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->assertSee('✓ Correcto')
            ->assertSee('✗ Incorrecto');
    }

    public function test_feedback_buttons_not_shown_on_unanalyzed_rows(): void
    {
        $admin = $this->createAdmin();
        $sitio = SitioWeb::factory()->create();

        ResultadoScraping::create([
            'url' => 'https://test.com/unanalyzed',
            'keyword' => 'unanalyzed test',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 20,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->assertDontSee('✓ Correcto')
            ->assertDontSee('✗ Incorrecto');
    }

    // ─── 6.2 guardarFeedbackCorrecto ──────────────────────────────────────────

    public function test_guardar_feedback_correcto_creates_feedback_record(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('guardarFeedbackCorrecto', $resultado->id);

        $this->assertDatabaseHas('clasificaciones_feedback', [
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $admin->id,
            'tipo' => 'correcto',
        ]);
    }

    public function test_guardar_feedback_correcto_is_idempotent_upsert(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        // First: save as incorrecto
        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $admin->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
        ]);

        // Now call correcto
        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('guardarFeedbackCorrecto', $resultado->id);

        // Should be exactly ONE record
        $this->assertSame(1, ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $admin->id)
            ->count());

        // And tipo should be correcto
        $this->assertSame('correcto', ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $admin->id)
            ->first()
            ->tipo->value);
    }

    public function test_guardar_feedback_correcto_throws_403_for_operador(): void
    {
        $operador = $this->createOperador();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($operador)
            ->test(Resultados::class)
            ->call('guardarFeedbackCorrecto', $resultado->id)
            ->assertForbidden();
    }

    // ─── 6.3 abrirModalFeedbackIncorrecto ─────────────────────────────────────

    public function test_abrir_modal_feedback_incorrecto_sets_modal_id(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->assertSet('feedbackModalId', $resultado->id);
    }

    public function test_abrir_modal_pre_fills_from_existing_feedback(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $admin->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
            'corregido_categoria' => CategoriaCorreccion::OPI,
            'motivo' => 'Este es el motivo de corrección.',
            'corregido_nombre' => 'Nombre Corregido',
        ]);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->assertSet('feedbackModalId', $resultado->id)
            ->assertSet('feedbackCategoriaCorregida', 'OPI')
            ->assertSet('feedbackMotivo', 'Este es el motivo de corrección.')
            ->assertSet('feedbackNombreCorregido', 'Nombre Corregido');
    }

    // ─── 6.3.4 / 6.3.5 Validation ─────────────────────────────────────────────

    public function test_guardar_feedback_incorrecto_fails_when_categoria_missing(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('feedbackModalId', $resultado->id)
            ->set('feedbackCategoriaCorregida', '')
            ->set('feedbackMotivo', 'Este motivo tiene mas de 10 chars')
            ->call('guardarFeedbackIncorrecto')
            ->assertHasErrors(['feedbackCategoriaCorregida']);
    }

    public function test_guardar_feedback_incorrecto_fails_when_motivo_empty(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('feedbackModalId', $resultado->id)
            ->set('feedbackCategoriaCorregida', 'PEP')
            ->set('feedbackMotivo', '')
            ->call('guardarFeedbackIncorrecto')
            ->assertHasErrors(['feedbackMotivo']);
    }

    public function test_guardar_feedback_incorrecto_fails_when_motivo_too_short(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('feedbackModalId', $resultado->id)
            ->set('feedbackCategoriaCorregida', 'PEP')
            ->set('feedbackMotivo', 'corto')
            ->call('guardarFeedbackIncorrecto')
            ->assertHasErrors(['feedbackMotivo']);
    }

    // ─── 6.3.6 Happy Path ──────────────────────────────────────────────────────

    public function test_guardar_feedback_incorrecto_happy_path_creates_record(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('feedbackModalId', $resultado->id)
            ->set('feedbackCategoriaCorregida', 'OPI')
            ->set('feedbackMotivo', 'La clasificacion es incorrecta porque...')
            ->call('guardarFeedbackIncorrecto')
            ->assertHasNoErrors()
            ->assertSet('feedbackModalId', null);

        $this->assertDatabaseHas('clasificaciones_feedback', [
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $admin->id,
            'tipo' => 'incorrecto',
        ]);
    }

    // ─── 6.3.7 Upsert correcto → incorrecto ───────────────────────────────────

    public function test_guardar_feedback_incorrecto_upsert_from_correcto(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        // Existing correcto
        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $admin->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
        ]);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('feedbackModalId', $resultado->id)
            ->set('feedbackCategoriaCorregida', 'OPI')
            ->set('feedbackMotivo', 'La clasificacion es incorrecta porque es OPI')
            ->call('guardarFeedbackIncorrecto');

        // Only ONE record
        $this->assertSame(1, ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $admin->id)
            ->count());

        // Now incorrecto
        $this->assertSame('incorrecto', ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $admin->id)
            ->first()
            ->tipo->value);
    }

    // ─── 6.3.10 cerrarModalFeedback ───────────────────────────────────────────

    public function test_cerrar_modal_feedback_resets_all_props(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createAnalyzedResultado();

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('feedbackModalId', $resultado->id)
            ->set('feedbackCategoriaCorregida', 'PEP')
            ->set('feedbackMotivo', 'Un motivo cualquiera de prueba')
            ->set('feedbackNombreCorregido', 'Nombre')
            ->set('feedbackCargoCorregido', 'Cargo')
            ->set('feedbackIsPepCorregido', true)
            ->call('cerrarModalFeedback')
            ->assertSet('feedbackModalId', null)
            ->assertSet('feedbackCategoriaCorregida', null)
            ->assertSet('feedbackMotivo', '')
            ->assertSet('feedbackNombreCorregido', null)
            ->assertSet('feedbackCargoCorregido', null)
            ->assertSet('feedbackIsPepCorregido', null);
    }
}
