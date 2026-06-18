<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Gaceta;

use App\Livewire\Gaceta\Eventos;
use App\Models\GacetaEventoPep;
use App\Models\GacetaNorma;
use App\Models\Pais;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 6 — Livewire review queue for Gaceta PEP appointment events.
 *
 * SCNs covered:
 *   6.1 Pending-list: only pendiente events appear; aprobado/rechazado excluded.
 *   6.2 Pais filter resets pagination to page 1.
 *   6.3 aprobar() sets estado_revision=aprobado + revisado_por + revisado_at.
 *   6.4 rechazar() sets estado_revision=rechazado + revisado_por + revisado_at.
 *
 * gaceta-decretos-collector · PR3
 */
class EventosTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function makeOperador(): User
    {
        $this->seed(RolesPermisosSeeder::class);
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('operador');

        return $user;
    }

    private function makePais(string $codigo = 'BO', string $nombre = 'Bolivia'): Pais
    {
        return Pais::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $nombre, 'activo' => true],
        );
    }

    private function makeNorma(string $pais = 'BO', int $externalId = 1): GacetaNorma
    {
        $this->makePais($pais);

        return GacetaNorma::create([
            'pais'              => $pais,
            'gaceta_id_externo' => $externalId,
            'tipo_norma'        => 'decreto_supremo',
            'sumario'           => 'Designa al ciudadano Juan Pérez como Ministro de Salud',
            'estado_extraccion' => 'procesado',
        ]);
    }

    private function makeEvento(GacetaNorma $norma, string $estado = 'pendiente', string $nombre = 'Juan Pérez'): GacetaEventoPep
    {
        return GacetaEventoPep::create([
            'gaceta_norma_id'           => $norma->id,
            'pais'                       => $norma->pais,
            'persona_nombre'             => $nombre,
            'persona_nombre_normalizado' => mb_strtoupper($nombre),
            'cargo'                      => 'Ministro de Salud',
            'tipo_evento'                => 'designacion',
            'interino'                   => false,
            'estado_revision'            => $estado,
        ]);
    }

    // ─── T0: Auth gate (security regression) ─────────────────────────────────

    /**
     * Unauthenticated user accessing /gaceta/eventos is redirected to login.
     *
     * REQ-AUTH / SCN-auth.1
     */
    public function test_gaceta_eventos_requires_authentication(): void
    {
        $this->get('/gaceta/eventos')
            ->assertRedirect(route('login'));
    }

    /**
     * Operador (no 'gestionar resultados' permission) gets 403 from mount().
     *
     * REQ-AUTH / SCN-auth.2
     */
    public function test_gaceta_eventos_operator_without_permission_gets_403(): void
    {
        $operador = $this->makeOperador();

        Livewire::actingAs($operador)
            ->test(Eventos::class)
            ->assertForbidden();
    }

    /**
     * Admin (has 'gestionar resultados') can mount the component successfully.
     *
     * REQ-AUTH / SCN-auth.3 — positive case proving the gate allows the right role.
     */
    public function test_gaceta_eventos_admin_with_permission_gets_200(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->assertOk();
    }

    // ─── T1: Renders pending list (6.1) ──────────────────────────────────────

    /**
     * The component only shows pendiente events; aprobado events are excluded.
     */
    public function test_gaceta_eventos_livewire_renders_pending_list(): void
    {
        $admin  = $this->makeAdmin();
        $norma  = $this->makeNorma();

        $pendiente = $this->makeEvento($norma, 'pendiente', 'Juan Pérez');
        $aprobado  = $this->makeEvento($norma, 'aprobado',  'Ana García');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->assertViewHas('eventos', function ($eventos) use ($pendiente, $aprobado) {
                $ids = $eventos->pluck('id');

                return $ids->contains($pendiente->id) && ! $ids->contains($aprobado->id);
            });
    }

    /**
     * Triangulation: rechazado events are also excluded from the pending list.
     */
    public function test_gaceta_eventos_excludes_rechazado_from_pending_list(): void
    {
        $admin    = $this->makeAdmin();
        $norma    = $this->makeNorma();

        $pendiente = $this->makeEvento($norma, 'pendiente', 'Juan Pérez');
        $rechazado = $this->makeEvento($norma, 'rechazado', 'Luis Gomez');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->assertViewHas('eventos', function ($eventos) use ($pendiente, $rechazado) {
                $ids = $eventos->pluck('id');

                return $ids->contains($pendiente->id) && ! $ids->contains($rechazado->id);
            });
    }

    // ─── T2: Pais filter resets pagination (6.2) ─────────────────────────────

    /**
     * Setting the pais filter resets the paginator back to page 1.
     */
    public function test_gaceta_eventos_filter_by_pais_resets_page(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('BO');

        // Create 25 pending BO events so page 2 exists
        for ($i = 1; $i <= 25; $i++) {
            $this->makeEvento($norma, 'pendiente', "Persona BO {$i}");
        }

        $component = Livewire::actingAs($admin)->test(Eventos::class);

        // Navigate to page 2
        $component->call('gotoPage', 2);

        // Apply pais filter — must reset to page 1
        $component->set('pais', 'BO');

        $component->assertSet('paginators', ['page' => 1]);
    }

    /**
     * Triangulation: switching to a different pais also resets to page 1.
     */
    public function test_gaceta_eventos_switching_pais_filter_resets_page(): void
    {
        $admin   = $this->makeAdmin();
        $normaBO = $this->makeNorma('BO', 1);
        $normaHN = $this->makeNorma('HN', 2);

        // 25 BO events so page 2 exists, 1 HN event for non-empty triangulation
        for ($i = 1; $i <= 25; $i++) {
            $this->makeEvento($normaBO, 'pendiente', "Persona BO {$i}");
        }
        $this->makeEvento($normaHN, 'pendiente', 'Persona HN 1');

        $component = Livewire::actingAs($admin)->test(Eventos::class);
        $component->call('gotoPage', 2);

        // Switch to HN pais — page must reset
        $component->set('pais', 'HN');

        $component->assertSet('paginators', ['page' => 1]);
    }

    // ─── T3: Aprobar sets aprobado (6.3) ─────────────────────────────────────

    /**
     * Calling aprobar() sets estado_revision=aprobado and stamps revisado_por + revisado_at.
     */
    public function test_gaceta_eventos_approve_sets_estado_aprobado(): void
    {
        $admin  = $this->makeAdmin();
        $norma  = $this->makeNorma();
        $evento = $this->makeEvento($norma, 'pendiente');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->call('aprobar', $evento->id);

        $this->assertDatabaseHas('gaceta_eventos_pep', [
            'id'              => $evento->id,
            'estado_revision' => 'aprobado',
            'revisado_por'    => $admin->id,
        ]);

        $updated = GacetaEventoPep::find($evento->id);
        $this->assertNotNull($updated?->revisado_at);
    }

    /**
     * Triangulation: aprobar() on a requiere_revision event also stamps aprobado.
     */
    public function test_gaceta_eventos_approve_requiere_revision_event(): void
    {
        $admin  = $this->makeAdmin();
        $norma  = $this->makeNorma();
        $evento = $this->makeEvento($norma, 'requiere_revision');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->call('aprobar', $evento->id);

        $this->assertDatabaseHas('gaceta_eventos_pep', [
            'id'              => $evento->id,
            'estado_revision' => 'aprobado',
            'revisado_por'    => $admin->id,
        ]);
    }

    // ─── T5: PDF link in Decreto column (SCN-pdf-link) ───────────────────────

    /**
     * When gacetaNorma has a pdf_url the decree number is rendered as an anchor
     * pointing to that URL and opening in a new tab.
     *
     * SCN-pdf-link.1
     */
    public function test_gaceta_eventos_decree_links_to_pdf_when_url_present(): void
    {
        $admin = $this->makeAdmin();
        $this->makePais();

        $norma = GacetaNorma::create([
            'pais'              => 'BO',
            'gaceta_id_externo' => 99,
            'tipo_norma'        => 'decreto_supremo',
            'numero_decreto'    => '0042',
            'sumario'           => 'Test sumario',
            'estado_extraccion' => 'procesado',
            'pdf_url'           => 'https://gaceta.bo/pdf/decreto-42.pdf',
        ]);

        $this->makeEvento($norma, 'pendiente', 'Carlos Ruiz');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->assertSeeHtml('href="https://gaceta.bo/pdf/decreto-42.pdf"')
            ->assertSeeHtml('target="_blank"');
    }

    /**
     * When gacetaNorma has no pdf_url, no empty/broken anchor is rendered — decree
     * text appears as plain text.
     *
     * SCN-pdf-link.2 (triangulation — forces the conditional branch in the blade)
     */
    public function test_gaceta_eventos_decree_shows_plain_text_when_no_pdf_url(): void
    {
        $admin = $this->makeAdmin();
        $this->makePais();

        $norma = GacetaNorma::create([
            'pais'              => 'BO',
            'gaceta_id_externo' => 100,
            'tipo_norma'        => 'decreto_supremo',
            'numero_decreto'    => '0043',
            'sumario'           => 'Test sumario sin pdf',
            'estado_extraccion' => 'procesado',
            'pdf_url'           => null,
        ]);

        $this->makeEvento($norma, 'pendiente', 'María Torres');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->assertSeeHtml('DS 0043')
            ->assertDontSeeHtml('href=""');
    }

    // ─── T5: Estado filter — aprobado (SCN-estado.1) ─────────────────────────

    /**
     * Setting estado='aprobado' shows only aprobado events; pendiente excluded.
     */
    public function test_gaceta_eventos_estado_filter_shows_only_aprobados(): void
    {
        $admin     = $this->makeAdmin();
        $norma     = $this->makeNorma();
        $pendiente = $this->makeEvento($norma, 'pendiente', 'Juan Pérez');
        $aprobado  = $this->makeEvento($norma, 'aprobado', 'Ana García');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->set('estado', 'aprobado')
            ->assertViewHas('eventos', function ($eventos) use ($pendiente, $aprobado) {
                $ids = $eventos->pluck('id');

                return $ids->contains($aprobado->id) && ! $ids->contains($pendiente->id);
            });
    }

    /**
     * Triangulation: estado='rechazado' shows only rechazado; pendiente excluded.
     *
     * SCN-estado.2
     */
    public function test_gaceta_eventos_estado_filter_shows_only_rechazados(): void
    {
        $admin     = $this->makeAdmin();
        $norma     = $this->makeNorma();
        $pendiente = $this->makeEvento($norma, 'pendiente', 'Juan Pérez');
        $rechazado = $this->makeEvento($norma, 'rechazado', 'Luis Gomez');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->set('estado', 'rechazado')
            ->assertViewHas('eventos', function ($eventos) use ($pendiente, $rechazado) {
                $ids = $eventos->pluck('id');

                return $ids->contains($rechazado->id) && ! $ids->contains($pendiente->id);
            });
    }

    /**
     * Triangulation: estado='' (todos) shows all three estados simultaneously.
     *
     * SCN-estado.3
     */
    public function test_gaceta_eventos_estado_filter_todos_shows_all_estados(): void
    {
        $admin     = $this->makeAdmin();
        $norma     = $this->makeNorma();
        $pendiente = $this->makeEvento($norma, 'pendiente', 'Juan Pérez');
        $aprobado  = $this->makeEvento($norma, 'aprobado', 'Ana García');
        $rechazado = $this->makeEvento($norma, 'rechazado', 'Luis Gomez');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->set('estado', '')
            ->assertViewHas('eventos', function ($eventos) use ($pendiente, $aprobado, $rechazado) {
                $ids = $eventos->pluck('id');

                return $ids->contains($pendiente->id)
                    && $ids->contains($aprobado->id)
                    && $ids->contains($rechazado->id);
            });
    }

    // ─── T6: Estado filter resets pagination (SCN-estado.4) ──────────────────

    /**
     * Changing the estado filter resets the paginator back to page 1.
     */
    public function test_gaceta_eventos_changing_estado_resets_page(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma();

        // 25 pending events so page 2 exists at default estado=pendiente
        for ($i = 1; $i <= 25; $i++) {
            $this->makeEvento($norma, 'pendiente', "Persona {$i}");
        }

        $component = Livewire::actingAs($admin)->test(Eventos::class);

        // Navigate to page 2 on the pending list
        $component->call('gotoPage', 2);

        // Change estado — must reset to page 1
        $component->set('estado', '');

        $component->assertSet('paginators', ['page' => 1]);
    }

    // ─── T7: Default estado is pendiente (SCN-estado.5) ──────────────────────

    /**
     * The component mounts with estado='pendiente' by default so existing
     * pending-list behaviour is preserved as a regression guard.
     */
    public function test_gaceta_eventos_default_estado_is_pendiente(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->assertSet('estado', 'pendiente');
    }

    // ─── T8: Reviewer columns and badge vs buttons (SCN-estado.6) ────────────

    /**
     * An aprobado row renders the reviewer's name in the "Revisado por" column.
     */
    public function test_gaceta_eventos_aprobado_row_shows_reviewer_name(): void
    {
        $admin    = $this->makeAdmin();
        $reviewer = User::factory()->create(['activo' => true, 'name' => 'Revisor Ejemplo']);
        $norma    = $this->makeNorma();

        GacetaEventoPep::create([
            'gaceta_norma_id'           => $norma->id,
            'pais'                      => $norma->pais,
            'persona_nombre'            => 'Carlos Ruiz',
            'persona_nombre_normalizado' => 'CARLOS RUIZ',
            'cargo'                     => 'Ministro de Hacienda',
            'tipo_evento'               => 'designacion',
            'interino'                  => false,
            'estado_revision'           => 'aprobado',
            'revisado_por'              => $reviewer->id,
            'revisado_at'               => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->set('estado', 'aprobado')
            ->assertSeeHtml('Revisor Ejemplo');
    }

    /**
     * Triangulation: an aprobado row hides Aprobar/Rechazar buttons and shows a badge.
     *
     * SCN-estado.7
     */
    public function test_gaceta_eventos_aprobado_row_hides_action_buttons(): void
    {
        $admin    = $this->makeAdmin();
        $reviewer = User::factory()->create(['activo' => true]);
        $norma    = $this->makeNorma();

        GacetaEventoPep::create([
            'gaceta_norma_id'           => $norma->id,
            'pais'                      => $norma->pais,
            'persona_nombre'            => 'Ana García',
            'persona_nombre_normalizado' => 'ANA GARCÍA',
            'cargo'                     => 'Ministra de Salud',
            'tipo_evento'               => 'designacion',
            'interino'                  => false,
            'estado_revision'           => 'aprobado',
            'revisado_por'              => $reviewer->id,
            'revisado_at'               => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->set('estado', 'aprobado')
            ->assertDontSeeHtml('wire:click="aprobar(')
            ->assertDontSeeHtml('wire:click="rechazar(')
            ->assertSeeHtml('Aprobado');
    }

    /**
     * A pendiente row still renders Aprobar and Rechazar action buttons.
     *
     * Regression guard: ensures the conditional in the view keeps showing
     * buttons for rows that are still pending.
     *
     * SCN-estado.8
     */
    public function test_gaceta_eventos_pendiente_row_shows_action_buttons(): void
    {
        $admin  = $this->makeAdmin();
        $norma  = $this->makeNorma();
        $evento = $this->makeEvento($norma, 'pendiente', 'Juan Pérez');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->assertSeeHtml("wire:click=\"aprobar({$evento->id})\"")
            ->assertSeeHtml("wire:click=\"rechazar({$evento->id})\"");
    }

    // ─── T4: Rechazar sets rechazado (6.4) ───────────────────────────────────

    /**
     * Calling rechazar() sets estado_revision=rechazado and stamps revisado_por + revisado_at.
     */
    public function test_gaceta_eventos_reject_sets_estado_rechazado(): void
    {
        $admin  = $this->makeAdmin();
        $norma  = $this->makeNorma();
        $evento = $this->makeEvento($norma, 'pendiente');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->call('rechazar', $evento->id);

        $this->assertDatabaseHas('gaceta_eventos_pep', [
            'id'              => $evento->id,
            'estado_revision' => 'rechazado',
            'revisado_por'    => $admin->id,
        ]);

        $updated = GacetaEventoPep::find($evento->id);
        $this->assertNotNull($updated?->revisado_at);
    }

    /**
     * Triangulation: rechazar() on a requiere_revision event also stamps rechazado.
     */
    public function test_gaceta_eventos_reject_requiere_revision_event(): void
    {
        $admin  = $this->makeAdmin();
        $norma  = $this->makeNorma();
        $evento = $this->makeEvento($norma, 'requiere_revision');

        Livewire::actingAs($admin)
            ->test(Eventos::class)
            ->call('rechazar', $evento->id);

        $this->assertDatabaseHas('gaceta_eventos_pep', [
            'id'              => $evento->id,
            'estado_revision' => 'rechazado',
            'revisado_por'    => $admin->id,
        ]);
    }
}
