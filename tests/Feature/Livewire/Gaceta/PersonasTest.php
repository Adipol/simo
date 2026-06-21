<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Gaceta;

use App\Livewire\Gaceta\Personas;
use App\Models\GacetaEventoPep;
use App\Models\GacetaNorma;
use App\Models\Pais;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PEP person-profile view — grouped by persona_nombre_normalizado.
 *
 * SCNs covered:
 *   AUTH   Unauthenticated → redirect; operador → 403; admin → 200.
 *   T1     Grouping: multiple events per normalized name collapse to one row.
 *   T2     Only-interim person shows null cargo_titular.
 *   T3     Rejected events are excluded (count + appearance).
 *   T4     buscar filter + page reset.
 *   T5     pais filter + page reset.
 *   T6     seleccionar() detail: events, ordering, cargo_titular, toggle.
 *
 * gaceta-personas-view
 */
class PersonasTest extends TestCase
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
        string $pais = 'BO',
        int $externalId = 1,
        string $fecha = '2024-01-01',
        ?string $decreto = null,
        ?string $pdfUrl = null,
    ): GacetaNorma {
        $this->makePais($pais);

        return GacetaNorma::create([
            'pais'              => $pais,
            'gaceta_id_externo' => $externalId,
            'tipo_norma'        => 'decreto_supremo',
            'numero_decreto'    => $decreto,
            'sumario'           => 'Test sumario',
            'estado_extraccion' => 'procesado',
            'fecha_publicacion' => $fecha,
            'pdf_url'           => $pdfUrl,
        ]);
    }

    private function makeEvento(
        GacetaNorma $norma,
        bool $interino,
        string $cargo,
        string $nombre = 'Juan Pérez',
        string $estado = 'aprobado',
    ): GacetaEventoPep {
        return GacetaEventoPep::create([
            'gaceta_norma_id'            => $norma->id,
            'pais'                       => $norma->pais,
            'persona_nombre'             => $nombre,
            'persona_nombre_normalizado' => Str::lower(Str::ascii($nombre)),
            'cargo'                      => $cargo,
            'tipo_evento'                => 'designacion',
            'interino'                   => $interino,
            'estado_revision'            => $estado,
        ]);
    }

    private function makeEventoConReferenciado(
        GacetaNorma $norma,
        string $cargo,
        string $cargoReferenciado,
        string $nombre = 'Juan Pérez',
        string $estado = 'aprobado',
    ): GacetaEventoPep {
        return GacetaEventoPep::create([
            'gaceta_norma_id'            => $norma->id,
            'pais'                       => $norma->pais,
            'persona_nombre'             => $nombre,
            'persona_nombre_normalizado' => Str::lower(Str::ascii($nombre)),
            'cargo'                      => $cargo,
            'tipo_evento'                => 'designacion',
            'interino'                   => true,
            'cargo_referenciado'         => $cargoReferenciado,
            'estado_revision'            => $estado,
        ]);
    }

    // ─── T0: Auth gate ────────────────────────────────────────────────────────

    /**
     * Unauthenticated user accessing /gaceta/personas is redirected to login.
     *
     * REQ-AUTH / SCN-auth.1
     */
    public function test_gaceta_personas_requires_authentication(): void
    {
        $this->get('/gaceta/personas')
            ->assertRedirect(route('login'));
    }

    /**
     * Operador (no 'gestionar resultados' permission) gets 403 from mount().
     *
     * REQ-AUTH / SCN-auth.2
     */
    public function test_gaceta_personas_operator_without_permission_gets_403(): void
    {
        $operador = $this->makeOperador();

        Livewire::actingAs($operador)
            ->test(Personas::class)
            ->assertForbidden();
    }

    /**
     * Admin (has 'gestionar resultados') can mount the component successfully.
     *
     * REQ-AUTH / SCN-auth.3
     */
    public function test_gaceta_personas_admin_with_permission_gets_200(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->assertOk();
    }

    // ─── T1: Grouping ─────────────────────────────────────────────────────────

    /**
     * One person with 1 titular + 3 interim events across 4 decrees
     * collapses into a single row with total=4, the titular cargo, and
     * the correct date range.
     *
     * SCN-T1-group.1 — the core grouping requirement.
     */
    public function test_gaceta_personas_groups_multiple_events_per_person(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-10');
        $norma2 = $this->makeNorma('BO', 2, '2024-03-15');
        $norma3 = $this->makeNorma('BO', 3, '2024-06-20');
        $norma4 = $this->makeNorma('BO', 4, '2024-09-05');

        // 1 titular + 3 interim for the same person
        $this->makeEvento($norma1, false, 'Ministro de la Presidencia', 'José Luis Lupo Flores');
        $this->makeEvento($norma2, true,  'Ministro Interino de RR.EE.', 'José Luis Lupo Flores');
        $this->makeEvento($norma3, true,  'Ministro Interino de RR.EE.', 'José Luis Lupo Flores');
        $this->makeEvento($norma4, true,  'Ministro Interino de RR.EE.', 'José Luis Lupo Flores');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->assertViewHas('personas', function ($personas) {
                if ($personas->total() !== 1) {
                    return false;
                }

                $p = $personas->first();

                return $p->total_designaciones == 4
                    && $p->cargo_titular === 'Ministro de la Presidencia'
                    && $p->desde === '2024-01-10'
                    && $p->hasta === '2024-09-05';
            });
    }

    /**
     * Triangulation: two persons with different normalized names produce two
     * separate rows — no cross-merge.
     *
     * SCN-T1-group.2
     */
    public function test_gaceta_personas_two_different_normalized_names_produce_two_rows(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-02-01');

        $this->makeEvento($norma1, false, 'Ministro de Salud',    'Juan Pérez');
        $this->makeEvento($norma2, false, 'Ministra de Educación', 'Ana García');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->assertViewHas('personas', fn ($p) => $p->total() === 2);
    }

    // ─── T2: Only-interim person ──────────────────────────────────────────────

    /**
     * A person with only interino=true events gets cargo_titular=null in the
     * list row (they have no titular designation on record).
     *
     * SCN-T2-interino.1
     */
    public function test_gaceta_personas_only_interim_events_shows_null_cargo_titular(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-03-01');

        $this->makeEvento($norma1, true, 'Ministro Interino de Hacienda', 'María Torres');
        $this->makeEvento($norma2, true, 'Ministro Interino de Educación', 'María Torres');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->assertViewHas('personas', function ($personas) {
                return $personas->total() === 1
                    && $personas->first()->cargo_titular === null;
            });
    }

    // ─── T3: Excludes rejected ────────────────────────────────────────────────

    /**
     * Rejected events are excluded from the total count.
     * A person with 1 aprobado + 1 rechazado shows total=1.
     *
     * SCN-T3-rechazado.1
     */
    public function test_gaceta_personas_excludes_rejected_events_from_count(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-02-01');

        $this->makeEvento($norma1, false, 'Ministro de Salud',     'Juan Pérez', 'aprobado');
        $this->makeEvento($norma2, false, 'Ministro de Educación', 'Juan Pérez', 'rechazado');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->assertViewHas('personas', function ($personas) {
                return $personas->total() === 1
                    && $personas->first()->total_designaciones == 1;
            });
    }

    /**
     * Triangulation: a person whose only events are rechazado does not appear
     * in the list at all.
     *
     * SCN-T3-rechazado.2
     */
    public function test_gaceta_personas_person_with_only_rejected_events_does_not_appear(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('BO', 1, '2024-01-01');

        $this->makeEvento($norma, false, 'Ministro de Salud', 'Juan Pérez', 'rechazado');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->assertViewHas('personas', fn ($p) => $p->total() === 0);
    }

    /**
     * Triangulation: pendiente events count (they are not rejected).
     *
     * SCN-T3-pendiente.1
     */
    public function test_gaceta_personas_includes_pendiente_events(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-02-01');

        $this->makeEvento($norma1, false, 'Ministro de Salud',     'Juan Pérez', 'aprobado');
        $this->makeEvento($norma2, false, 'Ministro de Educación', 'Juan Pérez', 'pendiente');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->assertViewHas('personas', fn ($p) => $p->total() === 1
                && $p->first()->total_designaciones == 2);
    }

    // ─── T4: buscar filter ────────────────────────────────────────────────────

    /**
     * Setting buscar returns only matching persons (by normalized name LIKE).
     *
     * SCN-T4-buscar.1
     */
    public function test_gaceta_personas_buscar_filter_returns_matching_persons(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-02-01');

        $this->makeEvento($norma1, false, 'Ministro de Salud',    'Juan Pérez');
        $this->makeEvento($norma2, false, 'Ministra de Educación', 'Ana García');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->set('buscar', 'Juan')
            ->assertViewHas('personas', function ($personas) {
                return $personas->total() === 1
                    && str_contains($personas->first()->persona_nombre, 'Juan');
            });
    }

    /**
     * Triangulation: accent-insensitive search — "Perez" matches "juan perez".
     *
     * SCN-T4-buscar.2
     */
    public function test_gaceta_personas_buscar_is_accent_insensitive(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('BO', 1, '2024-01-01');

        $this->makeEvento($norma, false, 'Ministro de Salud', 'Juan Pérez');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->set('buscar', 'Perez')     // no accent — should still match
            ->assertViewHas('personas', fn ($p) => $p->total() === 1);
    }

    /**
     * buscar with no match returns empty list.
     *
     * SCN-T4-buscar.3
     */
    public function test_gaceta_personas_buscar_no_match_returns_empty(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('BO', 1, '2024-01-01');

        $this->makeEvento($norma, false, 'Ministro de Salud', 'Juan Pérez');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->set('buscar', 'NoExiste')
            ->assertViewHas('personas', fn ($p) => $p->total() === 0);
    }

    /**
     * Changing buscar resets the paginator back to page 1.
     *
     * SCN-T4-buscar.4
     */
    public function test_gaceta_personas_buscar_filter_resets_page(): void
    {
        $admin = $this->makeAdmin();
        $norma = $this->makeNorma('BO', 1, '2024-01-01');

        // 25 distinct persons → page 2 exists
        for ($i = 1; $i <= 25; $i++) {
            $this->makeEvento($norma, false, "Cargo {$i}", "Persona Test {$i}");
        }

        $component = Livewire::actingAs($admin)->test(Personas::class);
        $component->call('gotoPage', 2);

        $component->set('buscar', 'Persona Test 1');

        $component->assertSet('paginators', ['page' => 1]);
    }

    // ─── T5: pais filter ──────────────────────────────────────────────────────

    /**
     * pais filter returns only persons from that country.
     *
     * SCN-T5-pais.1
     */
    public function test_gaceta_personas_pais_filter_returns_only_matching_country(): void
    {
        $admin   = $this->makeAdmin();
        $normaBO = $this->makeNorma('BO', 1, '2024-01-01');
        $normaHN = $this->makeNorma('HN', 2, '2024-01-01');

        $this->makeEvento($normaBO, false, 'Ministro de Salud',    'Juan Pérez');
        $this->makeEvento($normaHN, false, 'Ministra de Educación', 'Ana García');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->set('pais', 'BO')
            ->assertViewHas('personas', function ($personas) {
                return $personas->total() === 1
                    && str_contains($personas->first()->persona_nombre, 'Juan');
            });
    }

    /**
     * Triangulation: switching pais to HN shows HN person; BO person excluded.
     *
     * SCN-T5-pais.2
     */
    public function test_gaceta_personas_pais_filter_switches_country(): void
    {
        $admin   = $this->makeAdmin();
        $normaBO = $this->makeNorma('BO', 1, '2024-01-01');
        $normaHN = $this->makeNorma('HN', 2, '2024-01-01');

        $this->makeEvento($normaBO, false, 'Ministro de Salud',    'Juan Pérez');
        $this->makeEvento($normaHN, false, 'Ministra de Educación', 'Ana García');

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->set('pais', 'HN')
            ->assertViewHas('personas', function ($personas) {
                return $personas->total() === 1
                    && str_contains($personas->first()->persona_nombre, 'Ana');
            });
    }

    /**
     * Setting pais resets the paginator back to page 1.
     *
     * SCN-T5-pais.3
     */
    public function test_gaceta_personas_pais_filter_resets_page(): void
    {
        $admin  = $this->makeAdmin();
        $norma  = $this->makeNorma('BO', 1, '2024-01-01');

        // 25 distinct BO persons → page 2 exists
        for ($i = 1; $i <= 25; $i++) {
            $this->makeEvento($norma, false, "Cargo {$i}", "Persona BO {$i}");
        }

        $component = Livewire::actingAs($admin)->test(Personas::class);
        $component->call('gotoPage', 2);

        $component->set('pais', 'BO');

        $component->assertSet('paginators', ['page' => 1]);
    }

    // ─── T6: Detail / seleccionar ─────────────────────────────────────────────

    /**
     * seleccionar() loads all events for that person.
     *
     * SCN-T6-detalle.1
     */
    public function test_gaceta_personas_seleccionar_loads_person_detail(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-15');
        $norma2 = $this->makeNorma('BO', 2, '2024-03-20');

        $e1 = $this->makeEvento($norma1, false, 'Ministro de Salud',         'Juan Pérez');
        $e2 = $this->makeEvento($norma2, true,  'Ministro Interino de RR.EE.', 'Juan Pérez');

        $normalizado = Str::lower(Str::ascii('Juan Pérez'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('detalle', function ($detalle) use ($e1, $e2) {
                $ids = $detalle->pluck('id');

                return $ids->contains($e1->id) && $ids->contains($e2->id);
            });
    }

    /**
     * cargoTitular shows the latest non-interim event's cargo.
     *
     * SCN-T6-detalle.2
     */
    public function test_gaceta_personas_seleccionar_shows_latest_titular_cargo(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-15');
        $norma2 = $this->makeNorma('BO', 2, '2024-06-20');

        // Older titular
        $this->makeEvento($norma1, false, 'Viceministro de Hacienda', 'Juan Pérez');
        // Newer titular — this is the one that should surface
        $this->makeEvento($norma2, false, 'Ministro de Hacienda', 'Juan Pérez');

        $normalizado = Str::lower(Str::ascii('Juan Pérez'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('cargoTitular', 'Ministro de Hacienda');
    }

    /**
     * Triangulation: person with only interim events → cargoTitular is null.
     *
     * SCN-T6-detalle.3
     */
    public function test_gaceta_personas_seleccionar_only_interim_shows_null_cargo_titular(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-15');

        $this->makeEvento($norma1, true, 'Ministro Interino de Hacienda', 'María Torres');

        $normalizado = Str::lower(Str::ascii('María Torres'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('cargoTitular', null);
    }

    /**
     * Detail events are returned newest first (by gacetaNorma.fecha_publicacion DESC).
     *
     * SCN-T6-detalle.4
     */
    public function test_gaceta_personas_detalle_is_ordered_newest_first(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-06-01');

        $older  = $this->makeEvento($norma1, false, 'Cargo Antiguo',  'Juan Pérez');
        $recent = $this->makeEvento($norma2, false, 'Cargo Reciente', 'Juan Pérez');

        $normalizado = Str::lower(Str::ascii('Juan Pérez'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('detalle', function ($detalle) use ($recent, $older) {
                return $detalle->first()->id === $recent->id
                    && $detalle->last()->id  === $older->id;
            });
    }

    /**
     * Calling seleccionar() with the same normalizado again deselects (toggle).
     *
     * SCN-T6-detalle.5
     */
    public function test_gaceta_personas_seleccionar_toggle_deselects_person(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-15');

        $this->makeEvento($norma1, false, 'Ministro de Salud', 'Juan Pérez');

        $normalizado = Str::lower(Str::ascii('Juan Pérez'));

        $component = Livewire::actingAs($admin)->test(Personas::class);

        // Select
        $component->call('seleccionar', $normalizado);
        $component->assertSet('personaSeleccionada', $normalizado);

        // Toggle off
        $component->call('seleccionar', $normalizado);
        $component->assertSet('personaSeleccionada', '');
    }

    /**
     * Detail excludes rejected events even for the selected person.
     *
     * SCN-T6-detalle.6
     */
    public function test_gaceta_personas_detalle_excludes_rejected_events(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-02-01');

        $aprobado  = $this->makeEvento($norma1, false, 'Ministro de Salud',     'Juan Pérez', 'aprobado');
        $rechazado = $this->makeEvento($norma2, false, 'Ministro de Educación', 'Juan Pérez', 'rechazado');

        $normalizado = Str::lower(Str::ascii('Juan Pérez'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('detalle', function ($detalle) use ($aprobado, $rechazado) {
                $ids = $detalle->pluck('id');

                return $ids->contains($aprobado->id) && ! $ids->contains($rechazado->id);
            });
    }

    // ─── T7: cargoTitular fallback to cargo_referenciado ─────────────────────

    /**
     * A person with only interim events that carry cargo_referenciado shows
     * cargo_referenciado as cargoTitular (the referenced titular role).
     *
     * SCN-T7-cargo-referenciado.1 — RED: cargoTitular currently returns null
     * for all-interim persons even when cargo_referenciado is set.
     */
    public function test_gaceta_personas_cargo_titular_falls_back_to_cargo_referenciado(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-15');
        $norma2 = $this->makeNorma('BO', 2, '2024-06-20');

        // Both events are interim — oldest and newest both carry cargo_referenciado
        $this->makeEventoConReferenciado($norma1, 'Ministro Interino de Hacienda', 'Ministro de la Presidencia', 'Jose Luis Lupo Flores');
        $this->makeEventoConReferenciado($norma2, 'Ministro Interino de RR.EE.',   'Ministro de la Presidencia', 'Jose Luis Lupo Flores');

        $normalizado = Str::lower(Str::ascii('Jose Luis Lupo Flores'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('cargoTitular', 'Ministro de la Presidencia');
    }

    /**
     * A real non-interim event takes precedence over cargo_referenciado.
     * Even when the person has interim events with cargo_referenciado set,
     * the non-interim cargo wins.
     *
     * SCN-T7-cargo-referenciado.2 — Triangulation: precedence rule.
     */
    public function test_gaceta_personas_real_titular_event_takes_precedence_over_cargo_referenciado(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-01');
        $norma2 = $this->makeNorma('BO', 2, '2024-06-01');

        // Older real titular event (interino=false, no cargo_referenciado)
        $this->makeEvento($norma1, false, 'Ministro de la Presidencia', 'Jose Luis Lupo Flores');
        // Newer interim event with a DIFFERENT cargo_referenciado — should NOT override
        $this->makeEventoConReferenciado($norma2, 'Ministro Interino de RR.EE.', 'Ministro de Economia', 'Jose Luis Lupo Flores');

        $normalizado = Str::lower(Str::ascii('Jose Luis Lupo Flores'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('cargoTitular', 'Ministro de la Presidencia');
    }

    /**
     * Triangulation: person with interim events but no cargo_referenciado → null.
     * Preserves existing behavior for interim-only persons without referenced cargo.
     *
     * SCN-T7-cargo-referenciado.3 — Regression guard.
     */
    public function test_gaceta_personas_interim_without_cargo_referenciado_shows_null(): void
    {
        $admin  = $this->makeAdmin();
        $norma1 = $this->makeNorma('BO', 1, '2024-01-15');

        $this->makeEvento($norma1, true, 'Ministro Interino de Hacienda', 'María Torres');

        $normalizado = Str::lower(Str::ascii('María Torres'));

        Livewire::actingAs($admin)
            ->test(Personas::class)
            ->call('seleccionar', $normalizado)
            ->assertViewHas('cargoTitular', null);
    }
}
