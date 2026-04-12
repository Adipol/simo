<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\CategoriaCorreccion;
use App\Livewire\Scraper\Resultados;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResultadosFeedbackNormalizacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermisosSeeder::class);
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function createResultado(array $overrides = []): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://test.com/article-'.uniqid(),
            'keyword' => 'test',
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
            'gemini_motivo' => 'Funcionario',
        ], $overrides));
    }

    // ─── 6.1: guardarFeedbackIncorrecto populates corregido_nombre_normalizado ─

    public function test_guardar_feedback_incorrecto_populates_corregido_nombre_normalizado(): void
    {
        $user = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->set('feedbackNombreCorregido', 'Dr. Juan Pérez')
            ->set('feedbackCategoriaCorregida', CategoriaCorreccion::PEP->value)
            ->set('feedbackMotivo', 'La clasificación es correcta como PEP senior')
            ->call('guardarFeedbackIncorrecto');

        $feedback = ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $user->id)
            ->first();

        $this->assertNotNull($feedback);
        $this->assertSame('Dr. Juan Pérez', $feedback->corregido_nombre);
        $this->assertSame('Juan Pérez', $feedback->corregido_nombre_normalizado);
    }

    public function test_guardar_feedback_strips_title_in_normalized(): void
    {
        $user = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->set('feedbackNombreCorregido', 'Ing. Carlos Ruiz')
            ->set('feedbackCategoriaCorregida', CategoriaCorreccion::PEP->value)
            ->set('feedbackMotivo', 'La clasificación es correcta como PEP senior')
            ->call('guardarFeedbackIncorrecto');

        $feedback = ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $user->id)
            ->first();

        $this->assertNotNull($feedback);
        $this->assertSame('Carlos Ruiz', $feedback->corregido_nombre_normalizado);
    }

    // ─── 6.2: Empty feedback name → null normalized ───────────────────────────

    public function test_guardar_feedback_with_null_nombre_sets_normalized_to_null(): void
    {
        $user = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->set('feedbackNombreCorregido', null)
            ->set('feedbackCategoriaCorregida', CategoriaCorreccion::PEP->value)
            ->set('feedbackMotivo', 'La clasificación es correcta como PEP senior')
            ->call('guardarFeedbackIncorrecto');

        $feedback = ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $user->id)
            ->first();

        $this->assertNotNull($feedback);
        $this->assertNull($feedback->corregido_nombre);
        $this->assertNull($feedback->corregido_nombre_normalizado);
    }

    // ─── 6.3: Upsert preserves normalized on update ───────────────────────────

    public function test_update_existing_feedback_updates_normalized_too(): void
    {
        $user = $this->createAdmin();
        $resultado = $this->createResultado();

        // First save: "Old Name"
        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->set('feedbackNombreCorregido', 'Old Name')
            ->set('feedbackCategoriaCorregida', CategoriaCorreccion::PEP->value)
            ->set('feedbackMotivo', 'La clasificación es correcta como PEP senior')
            ->call('guardarFeedbackIncorrecto');

        // Second save: "Dr. New Name"
        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->set('feedbackNombreCorregido', 'Dr. New Name')
            ->set('feedbackCategoriaCorregida', CategoriaCorreccion::PEP->value)
            ->set('feedbackMotivo', 'La clasificación es correcta como PEP senior')
            ->call('guardarFeedbackIncorrecto');

        $feedback = ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $user->id)
            ->first();

        $this->assertNotNull($feedback);
        $this->assertSame('Dr. New Name', $feedback->corregido_nombre);
        $this->assertSame('New Name', $feedback->corregido_nombre_normalizado);
    }

    // ─── 8.2: Full flow integration ───────────────────────────────────────────

    public function test_full_flow_feedback_submit_populates_both_columns(): void
    {
        $user = $this->createAdmin();
        $resultado = $this->createResultado();

        Livewire::actingAs($user)
            ->test(Resultados::class)
            ->call('abrirModalFeedbackIncorrecto', $resultado->id)
            ->set('feedbackNombreCorregido', 'Sra. MARÍA RODRÍGUEZ')
            ->set('feedbackCategoriaCorregida', CategoriaCorreccion::PEP->value)
            ->set('feedbackMotivo', 'La clasificación es correcta como PEP senior')
            ->call('guardarFeedbackIncorrecto');

        $feedback = ClasificacionFeedback::where('resultado_scraping_id', $resultado->id)
            ->where('usuario_id', $user->id)
            ->first();

        $this->assertNotNull($feedback);
        $this->assertSame('Sra. MARÍA RODRÍGUEZ', $feedback->corregido_nombre);
        $this->assertSame('María Rodríguez', $feedback->corregido_nombre_normalizado);
    }
}
