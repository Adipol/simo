<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Gaceta;

use App\Livewire\Gaceta\Normas;
use App\Models\GacetaEventoPep;
use App\Models\GacetaNorma;
use App\Models\Pais;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gaceta flagged-norma review queue.
 *
 * SCNs covered:
 *   AUTH   Unauthenticated → redirect; operador → 403; admin → 200.
 *   T1     Renders only requiere_revision + requiere_detalle; excludes the rest.
 *   T2     pais filter resets pagination.
 *   T3     tipo filter resets pagination.
 *   T4     descartar() stamps descartado + revisado_por/at; removes from queue.
 *   T5     agregarEvento() creates evento_pep; norma stays in queue; repeatable; validates.
 *   T6     marcarResuelto() stamps resuelto_manual + audit; removes from queue.
 */
class NormasTest extends TestCase
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

    private function makeNorma(
        string $estado = 'requiere_revision',
        string $pais = 'BO',
        int $externalId = 1,
    ): GacetaNorma {
        $this->makePais($pais);

        return GacetaNorma::create([
            'pais'              => $pais,
            'gaceta_id_externo' => $externalId,
            'tipo_norma'        => 'decreto_presidencial',
            'sumario'           => 'Designación del Alto Mando Militar para el período 2026-2027',
            'estado_extraccion' => $estado,
        ]);
    }

    // ─── T0: Auth gate (mirrors EventosTest exactly) ─────────────────────────

    /**
     * Unauthenticated request to /gaceta/normas redirects to login.
     *
     * REQ-AUTH / SCN-auth.1
     */
    public function test_gaceta_normas_requires_authentication(): void
    {
        $this->get('/gaceta/normas')
            ->assertRedirect(route('login'));
    }

    /**
     * Operador (no 'gestionar resultados' permission) gets 403 from mount().
     *
     * REQ-AUTH / SCN-auth.2
     */
    public function test_gaceta_normas_operator_without_permission_gets_403(): void
    {
        $operador = $this->makeOperador();

        Livewire::actingAs($operador)
            ->test(Normas::class)
            ->assertForbidden();
    }

    /**
     * Admin (has 'gestionar resultados') can mount the component successfully.
     *
     * REQ-AUTH / SCN-auth.3
     */
    public function test_gaceta_normas_admin_with_permission_gets_200(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->assertOk();
    }

    // ─── T1: Renders flagged normas only ─────────────────────────────────────

    /**
     * Both requiere_revision and requiere_detalle normas appear in the queue.
     */
    public function test_gaceta_normas_renders_requiere_revision_and_requiere_detalle(): void
    {
        $admin = $this->makeAdmin();
        $n1    = $this->makeNorma('requiere_revision', 'BO', 1);
        $n2    = $this->makeNorma('requiere_detalle', 'BO', 2);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->assertViewHas('normas', function ($normas) use ($n1, $n2) {
                $ids = $normas->pluck('id');

                return $ids->contains($n1->id) && $ids->contains($n2->id);
            });
    }

    /**
     * Triangulation: procesado, descartado, resuelto_manual are excluded.
     */
    public function test_gaceta_normas_excludes_procesado_descartado_resuelto_manual(): void
    {
        $admin      = $this->makeAdmin();
        $flagged    = $this->makeNorma('requiere_revision', 'BO', 1);
        $procesado  = $this->makeNorma('procesado', 'BO', 2);
        $descartado = $this->makeNorma('descartado', 'BO', 3);
        $resuelto   = $this->makeNorma('resuelto_manual', 'BO', 4);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->assertViewHas('normas', function ($normas) use ($flagged, $procesado, $descartado, $resuelto) {
                $ids = $normas->pluck('id');

                return $ids->contains($flagged->id)
                    && ! $ids->contains($procesado->id)
                    && ! $ids->contains($descartado->id)
                    && ! $ids->contains($resuelto->id);
            });
    }

    // ─── T2: pais filter resets page ─────────────────────────────────────────

    /**
     * Setting the pais filter resets the paginator back to page 1.
     */
    public function test_gaceta_normas_filter_by_pais_resets_page(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 1; $i <= 25; $i++) {
            $this->makeNorma('requiere_revision', 'BO', $i);
        }

        $component = Livewire::actingAs($admin)->test(Normas::class);
        $component->call('gotoPage', 2);
        $component->set('pais', 'BO');

        $component->assertSet('paginators', ['page' => 1]);
    }

    /**
     * Triangulation: switching to a different pais also resets to page 1.
     */
    public function test_gaceta_normas_switching_pais_filter_resets_page(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 1; $i <= 25; $i++) {
            $this->makeNorma('requiere_revision', 'BO', $i);
        }
        $this->makeNorma('requiere_revision', 'HN', 100);

        $component = Livewire::actingAs($admin)->test(Normas::class);
        $component->call('gotoPage', 2);
        $component->set('pais', 'HN');

        $component->assertSet('paginators', ['page' => 1]);
    }

    // ─── T3: tipo filter resets page ─────────────────────────────────────────

    /**
     * Setting the tipo filter resets the paginator back to page 1.
     */
    public function test_gaceta_normas_filter_by_tipo_resets_page(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 1; $i <= 25; $i++) {
            $this->makeNorma('requiere_revision', 'BO', $i);
        }

        $component = Livewire::actingAs($admin)->test(Normas::class);
        $component->call('gotoPage', 2);
        $component->set('tipo', 'requiere_revision');

        $component->assertSet('paginators', ['page' => 1]);
    }

    /**
     * Triangulation: switching tipo to requiere_detalle also resets to page 1.
     */
    public function test_gaceta_normas_switching_tipo_filter_resets_page(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 1; $i <= 25; $i++) {
            $this->makeNorma('requiere_revision', 'BO', $i);
        }
        $this->makeNorma('requiere_detalle', 'BO', 100);

        $component = Livewire::actingAs($admin)->test(Normas::class);
        $component->call('gotoPage', 2);
        $component->set('tipo', 'requiere_detalle');

        $component->assertSet('paginators', ['page' => 1]);
    }

    // ─── T4: descartar ───────────────────────────────────────────────────────

    /**
     * descartar() sets estado_extraccion=descartado and stamps revisado_por + revisado_at.
     */
    public function test_gaceta_normas_descartar_sets_descartado_and_stamps_reviewer(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_revision', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->call('descartar', $norma->id);

        $this->assertDatabaseHas('gaceta_normas', [
            'id'                => $norma->id,
            'estado_extraccion' => 'descartado',
            'revisado_por'      => $admin->id,
        ]);

        $updated = GacetaNorma::find($norma->id);
        $this->assertNotNull($updated?->revisado_at);
    }

    /**
     * Triangulation: descartar() also works on requiere_detalle normas.
     */
    public function test_gaceta_normas_descartar_requiere_detalle_norma(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_detalle', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->call('descartar', $norma->id);

        $this->assertDatabaseHas('gaceta_normas', [
            'id'                => $norma->id,
            'estado_extraccion' => 'descartado',
            'revisado_por'      => $admin->id,
        ]);
    }

    /**
     * After descartar(), the norma no longer appears in the queue.
     */
    public function test_gaceta_normas_descartar_removes_norma_from_queue(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_revision', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->call('descartar', $norma->id)
            ->assertViewHas('normas', function ($normas) use ($norma) {
                return ! $normas->pluck('id')->contains($norma->id);
            });
    }

    // ─── T5: agregarEvento ───────────────────────────────────────────────────

    /**
     * agregarEvento() creates a gaceta_eventos_pep row with pais from the norma,
     * estado_revision=aprobado, and revisado_por stamped.
     */
    public function test_gaceta_normas_agregar_evento_creates_evento_pep(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_detalle', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->set('personaNombre', 'Juan Pérez')
            ->set('cargo', 'Ministro de Defensa')
            ->call('agregarEvento', $norma->id);

        $this->assertDatabaseHas('gaceta_eventos_pep', [
            'gaceta_norma_id' => $norma->id,
            'pais'            => 'BO',
            'persona_nombre'  => 'Juan Pérez',
            'cargo'           => 'Ministro de Defensa',
            'tipo_evento'     => 'designacion',
            'estado_revision' => 'aprobado',
            'revisado_por'    => $admin->id,
        ]);
    }

    /**
     * After agregarEvento(), the norma STAYS in the queue (repeatable bulk extraction).
     */
    public function test_gaceta_normas_agregar_evento_norma_stays_in_queue(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_detalle', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->set('personaNombre', 'Juan Pérez')
            ->set('cargo', 'Ministro de Defensa')
            ->call('agregarEvento', $norma->id)
            ->assertViewHas('normas', function ($normas) use ($norma) {
                return $normas->pluck('id')->contains($norma->id);
            });
    }

    /**
     * Triangulation: a second event can be added to the same norma (repeatable).
     */
    public function test_gaceta_normas_agregar_evento_is_repeatable(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_detalle', 'BO', 1);

        $component = Livewire::actingAs($admin)->test(Normas::class);

        $component
            ->set('personaNombre', 'Juan Pérez')
            ->set('cargo', 'Ministro de Defensa')
            ->call('agregarEvento', $norma->id);

        $component
            ->set('personaNombre', 'Ana García')
            ->set('cargo', 'Viceministra de Salud')
            ->call('agregarEvento', $norma->id);

        $this->assertDatabaseCount('gaceta_eventos_pep', 2);
    }

    /**
     * Validation fails and no row is created when persona_nombre is blank.
     */
    public function test_gaceta_normas_agregar_evento_validation_fails_when_nombre_blank(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_detalle', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->set('personaNombre', '')
            ->set('cargo', 'Ministro de Defensa')
            ->call('agregarEvento', $norma->id)
            ->assertHasErrors(['personaNombre' => 'required']);

        $this->assertDatabaseCount('gaceta_eventos_pep', 0);
    }

    /**
     * Triangulation: validation fails when cargo is blank.
     */
    public function test_gaceta_normas_agregar_evento_validation_fails_when_cargo_blank(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_detalle', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->set('personaNombre', 'Juan Pérez')
            ->set('cargo', '')
            ->call('agregarEvento', $norma->id)
            ->assertHasErrors(['cargo' => 'required']);

        $this->assertDatabaseCount('gaceta_eventos_pep', 0);
    }

    // ─── T6: marcarResuelto ──────────────────────────────────────────────────

    /**
     * marcarResuelto() sets estado_extraccion=resuelto_manual and stamps audit fields.
     */
    public function test_gaceta_normas_marcar_resuelto_sets_resuelto_manual(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_detalle', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->call('marcarResuelto', $norma->id);

        $this->assertDatabaseHas('gaceta_normas', [
            'id'                => $norma->id,
            'estado_extraccion' => 'resuelto_manual',
            'revisado_por'      => $admin->id,
        ]);

        $updated = GacetaNorma::find($norma->id);
        $this->assertNotNull($updated?->revisado_at);
    }

    /**
     * Triangulation: marcarResuelto() also works on requiere_revision normas.
     */
    public function test_gaceta_normas_marcar_resuelto_requiere_revision_norma(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_revision', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->call('marcarResuelto', $norma->id);

        $this->assertDatabaseHas('gaceta_normas', [
            'id'                => $norma->id,
            'estado_extraccion' => 'resuelto_manual',
            'revisado_por'      => $admin->id,
        ]);
    }

    /**
     * After marcarResuelto(), the norma no longer appears in the queue.
     */
    public function test_gaceta_normas_marcar_resuelto_removes_norma_from_queue(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('requiere_revision', 'BO', 1);

        Livewire::actingAs($admin)
            ->test(Normas::class)
            ->call('marcarResuelto', $norma->id)
            ->assertViewHas('normas', function ($normas) use ($norma) {
                return ! $normas->pluck('id')->contains($norma->id);
            });
    }
}
