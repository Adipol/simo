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

class CambiosFiltroPersonaTest extends TestCase
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

    public function test_default_filter_shows_only_analyzed_cambios_with_persona(): void
    {
        // Record con persona_nueva — debe aparecer
        $conPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Juan Pérez',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'es_mae' => true,
                'analisis' => 'Cambio de autoridad',
            ],
        ]);

        // Record analizado SIN persona — no debe aparecer con filtro por defecto
        $sinPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Solo formateo',
            ],
        ]);

        // Record no analizado — no debe aparecer con filtro por defecto
        $noAnalizado = $this->makeCambio(['gemini_analyzed' => false]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertSee($conPersona->fuente->organismo)
            ->assertViewHas('cambios', function ($cambios) use ($conPersona, $sinPersona, $noAnalizado) {
                $ids = $cambios->pluck('id');

                return $ids->contains($conPersona->id)
                    && ! $ids->contains($sinPersona->id)
                    && ! $ids->contains($noAnalizado->id);
            });
    }

    public function test_default_filter_shows_analyzed_cambios_with_persona_removida(): void
    {
        // Record con persona_removida — también debe aparecer en default
        $conPersonaRemovida = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => 'Carlos López',
                'riesgo' => 'medio',
                'es_mae' => false,
                'analisis' => 'Persona removida detectada',
            ],
        ]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertViewHas('cambios', function ($cambios) use ($conPersonaRemovida) {
                return $cambios->pluck('id')->contains($conPersonaRemovida->id);
            });
    }

    public function test_filtro_todos_shows_all_records(): void
    {
        $conPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Ana García',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'es_mae' => true,
                'analisis' => 'Cambio de autoridad',
            ],
        ]);

        $sinPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Sin persona',
            ],
        ]);

        $noAnalizado = $this->makeCambio(['gemini_analyzed' => false]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', '')
            ->assertViewHas('cambios', function ($cambios) use ($conPersona, $sinPersona, $noAnalizado) {
                $ids = $cambios->pluck('id');

                return $ids->contains($conPersona->id)
                    && $ids->contains($sinPersona->id)
                    && $ids->contains($noAnalizado->id);
            });
    }

    public function test_filtro_sin_persona_shows_only_analyzed_no_persona_records(): void
    {
        $conPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Ana García',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'es_mae' => true,
                'analisis' => 'Cambio de autoridad',
            ],
        ]);

        $sinPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Solo formateo',
            ],
        ]);

        $noAnalizado = $this->makeCambio(['gemini_analyzed' => false]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', 'no')
            ->assertViewHas('cambios', function ($cambios) use ($conPersona, $sinPersona, $noAnalizado) {
                $ids = $cambios->pluck('id');

                return ! $ids->contains($conPersona->id)
                    && $ids->contains($sinPersona->id)
                    && ! $ids->contains($noAnalizado->id);
            });
    }

    public function test_banner_visible_when_filtro_con_persona_active(): void
    {
        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertSee('Mostrando solo cambios con personas');
    }

    public function test_banner_hidden_when_filtro_todos(): void
    {
        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', '')
            ->assertDontSee('Mostrando solo cambios con personas');
    }

    public function test_banner_hidden_when_filtro_sin_persona(): void
    {
        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', 'no')
            ->assertDontSee('Mostrando solo cambios con personas');
    }

    public function test_changing_filtro_con_persona_resets_pagination(): void
    {
        // Crear suficientes records para tener paginación (> 20)
        for ($i = 0; $i < 25; $i++) {
            $this->makeCambio([
                'gemini_analyzed' => true,
                'gemini_analisis_json' => [
                    'persona_nueva' => "Persona {$i}",
                    'persona_removida' => null,
                    'riesgo' => 'bajo',
                    'es_mae' => false,
                    'analisis' => "Cambio {$i}",
                ],
            ]);
        }

        $component = Livewire::actingAs($this->user)->test(Cambios::class);

        // Ir a página 2
        $component->call('gotoPage', 2);

        // Cambiar filtro — paginators debe resetearse a página 1
        $component->set('filtroConPersona', '');

        // En Livewire 4 con WithPagination, paginators['page'] = 1 indica que está en primera página
        $component->assertSet('paginators', ['page' => 1]);
    }

    public function test_default_filter_excludes_analyzed_no_persona_even_when_scraper_marked_posibles_peps(): void
    {
        // CASO REAL DEL BUG: scraper detectó "posibles_peps" pero Gemini
        // dictaminó que NO es persona (es una sección/categoría).
        // Gemini debe ganar: el cambio NO entra al filtro por defecto.
        $analizadoSinPersonaConPosibles = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Es una categoría institucional, no una persona.',
            ],
            'posibles_peps' => 'Proveedores de Software',
        ]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertViewHas('cambios', function ($cambios) use ($analizadoSinPersonaConPosibles) {
                return ! $cambios->pluck('id')->contains($analizadoSinPersonaConPosibles->id);
            });
    }

    public function test_default_filter_includes_pending_cambio_when_scraper_found_posibles_peps(): void
    {
        // Mientras Gemini no analizó, posibles_peps actúa como fallback.
        $pendingConPosibles = $this->makeCambio([
            'gemini_analyzed' => false,
            'gemini_analisis_json' => null,
            'posibles_peps' => 'Juan Pérez',
        ]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertViewHas('cambios', function ($cambios) use ($pendingConPosibles) {
                return $cambios->pluck('id')->contains($pendingConPosibles->id);
            });
    }

    public function test_default_filter_excludes_pending_cambio_without_scraper_signal(): void
    {
        // Pending sin señal del scraper: limbo total, no aparece en el default.
        $pendingSinSenal = $this->makeCambio([
            'gemini_analyzed' => false,
            'gemini_analisis_json' => null,
            'posibles_peps' => null,
        ]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->assertViewHas('cambios', function ($cambios) use ($pendingSinSenal) {
                return ! $cambios->pluck('id')->contains($pendingSinSenal->id);
            });
    }

    public function test_filtro_sin_persona_includes_analyzed_no_persona_even_with_posibles_peps(): void
    {
        // Mismo caso real: si Gemini dijo "no es persona", el filtro
        // "sin persona" SÍ debe incluirlo, aunque el scraper haya marcado
        // posibles_peps. Gemini manda.
        $analizadoSinPersonaConPosibles = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Categoría institucional.',
            ],
            'posibles_peps' => 'Proveedores de Software',
        ]);

        Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', 'no')
            ->assertViewHas('cambios', function ($cambios) use ($analizadoSinPersonaConPosibles) {
                return $cambios->pluck('id')->contains($analizadoSinPersonaConPosibles->id);
            });
    }

    public function test_muted_card_style_applied_for_no_person_low_risk_records(): void
    {
        // Registro analizado SIN personas → debe tener opacity-60 (esMuted = true)
        $sinPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
                'es_mae' => false,
                'analisis' => 'Solo formateo sin personas',
            ],
        ]);

        // Registro analizado CON persona → NO debe tener opacity-60 (esMuted = false)
        $conPersona = $this->makeCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Ana García',
                'persona_removida' => null,
                'riesgo' => 'alto',
                'es_mae' => true,
                'analisis' => 'Cambio de autoridad',
            ],
        ]);

        // Mostrar todos los registros para que ambos sean visibles
        $html = Livewire::actingAs($this->user)
            ->test(Cambios::class)
            ->set('filtroConPersona', '')
            ->html();

        // El registro sin persona debe tener opacity-60 en su wire:key div
        $this->assertStringContainsString('opacity-60', $html);

        // Verificar que el registro con persona no tiene opacity-60 en su tarjeta
        // Lo hacemos extrayendo el bloque del cambio con persona y verificando que no tiene opacity-60
        // La estructura del HTML es: wire:key="cambio-{id}" con opacity-60 solo en muted cards
        $sinPersonaKey = "wire:key=\"cambio-{$sinPersona->id}\"";
        $conPersonaKey = "wire:key=\"cambio-{$conPersona->id}\"";

        $this->assertStringContainsString($sinPersonaKey, $html);
        $this->assertStringContainsString($conPersonaKey, $html);

        // El bloque del registro sin persona contiene opacity-60
        $sinPersonaPos = strpos($html, $sinPersonaKey);
        $conPersonaPos = strpos($html, $conPersonaKey);

        // Extraer fragmento de 200 chars desde cada wire:key para verificar la clase
        $sinPersonaFragment = substr($html, (int) $sinPersonaPos, 200);
        $conPersonaFragment = substr($html, (int) $conPersonaPos, 200);

        $this->assertStringContainsString('opacity-60', $sinPersonaFragment);
        $this->assertStringNotContainsString('opacity-60', $conPersonaFragment);
    }
}
