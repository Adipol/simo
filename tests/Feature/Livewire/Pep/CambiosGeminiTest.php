<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Pep;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CambiosGeminiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable Gemini for tests
        config(['services.gemini.enabled' => false]);
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'activo' => true,
        ]);
    }

    private function createFuente(): Fuente
    {
        return Fuente::create([
            'organismo' => 'Test Organismo',
            'pais' => 'AR',
            'url' => 'https://test.example.com',
        ]);
    }

    #[Test]
    public function mae_badge_shown_when_gemini_detects_mae(): void
    {
        $user = $this->createUser();
        $fuente = $this->createFuente();

        $cambio = Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'lineas_quitadas' => 5,
            'lineas_nuevas' => 10,
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'es_mae' => true,
                'persona_nueva' => 'Juan Pérez',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'cargo' => 'Ministro de Economía',
                'analisis' => 'Cambio de ministro detectado.',
            ],
        ]);

        Livewire::actingAs($user)
            ->test('pep.cambios')
            ->assertSee('MAE')
            ->assertSeeText($cambio->id);
    }

    #[Test]
    public function mae_badge_not_shown_when_not_mae(): void
    {
        $user = $this->createUser();
        $fuente = $this->createFuente();

        Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'lineas_quitadas' => 5,
            'lineas_nuevas' => 10,
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'es_mae' => false,
                'riesgo' => 'bajo',
            ],
        ]);

        Livewire::actingAs($user)
            ->test('pep.cambios')
            ->assertDontSee('MAE');
    }

    #[Test]
    public function gemini_analysis_section_shows_in_diff_panel(): void
    {
        $user = $this->createUser();
        $fuente = $this->createFuente();

        $cambio = Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'lineas_quitadas' => 5,
            'lineas_nuevas' => 10,
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'es_mae' => true,
                'persona_removida' => 'Juan Pérez',
                'persona_nueva' => 'María García',
                'cargo' => 'Director General',
                'riesgo' => 'alto',
                'analisis' => 'Cambio detectado en posición de alto funcionario.',
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('pep.cambios');

        // Click "Ver diff" to open panel
        $component->call('toggleDiff', $cambio->id);

        // Assert Gemini analysis section content
        $component->assertSee('Análisis Gemini')
            ->assertSee('Juan Pérez')
            ->assertSee('María García')
            ->assertSee('Director General')
            ->assertSee('Cambio detectado en posición de alto funcionario.')
            ->assertSee('Riesgo: Alto');
    }

    #[Test]
    public function risk_level_colors_applied_correctly(): void
    {
        $user = $this->createUser();
        $fuente = $this->createFuente();

        $cambio = Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'lineas_quitadas' => 5,
            'lineas_nuevas' => 10,
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'es_mae' => false,
                'persona_nueva' => 'Ana García',
                'persona_removida' => null,
                'riesgo' => 'medio',
                'cargo' => 'Subdirector',
                'analisis' => 'Cambio de subdirector.',
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('pep.cambios')
            ->call('toggleDiff', $cambio->id);

        // Assert "medio" risk is shown (amber color applied via class)
        $component->assertSee('Riesgo: Medio');
    }

    #[Test]
    public function gemini_section_hidden_when_not_analyzed(): void
    {
        $user = $this->createUser();
        $fuente = $this->createFuente();

        $cambio = Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'lineas_quitadas' => 5,
            'lineas_nuevas' => 10,
            'gemini_analyzed' => false,
        ]);

        $component = Livewire::actingAs($user)
            ->test('pep.cambios')
            ->call('toggleDiff', $cambio->id);

        // Should NOT see Gemini analysis section
        $component->assertDontSee('Análisis Gemini');
    }

    #[Test]
    public function optional_fields_hidden_when_missing(): void
    {
        $user = $this->createUser();
        $fuente = $this->createFuente();

        $cambio = Cambio::create([
            'fuente_id' => $fuente->id,
            'fecha' => now(),
            'hash_anterior' => 'abc123',
            'hash_nuevo' => 'def456',
            'lineas_quitadas' => 5,
            'lineas_nuevas' => 10,
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'es_mae' => false,
                'persona_nueva' => 'Carlos Ruiz',
                'persona_removida' => null,
                'riesgo' => 'bajo',
                // No analisis
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('pep.cambios')
            ->call('toggleDiff', $cambio->id);

        // Should see section but not optional analisis field; persona_removida is null so "Removido:" hidden
        $component->assertSee('Análisis Gemini')
            ->assertDontSee('Removido:')
            ->assertSee('Nuevo:');
    }
}
