<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\EntidadesPublicas;
use App\Models\EntidadPublica;
use App\Models\Pais;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EntidadesPublicasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermisosSeeder::class);
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function createOperador(): User
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('operador');

        return $user;
    }

    private function createPais(string $codigo = 'AR', string $nombre = 'Argentina'): Pais
    {
        return Pais::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $nombre, 'activo' => true]
        );
    }

    private function createEntidad(array $overrides = []): EntidadPublica
    {
        $this->createPais();

        return EntidadPublica::create(array_merge([
            'pais_codigo' => 'AR',
            'nombre' => 'Ministerio de Economía',
            'sigla' => 'ME',
            'activo' => true,
        ], $overrides));
    }

    // ─── Route Protection ─────────────────────────────────────────────────────

    public function test_admin_can_access_entidades_page(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->get('/scraper/entidades-publicas');

        $response->assertOk();
    }

    public function test_operador_cannot_access(): void
    {
        $operador = $this->createOperador();

        $response = $this->actingAs($operador)->get('/scraper/entidades-publicas');

        $response->assertForbidden();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_admin_can_create_entidad(): void
    {
        $admin = $this->createAdmin();
        $this->createPais('AR', 'Argentina');

        Livewire::actingAs($admin)
            ->test(EntidadesPublicas::class)
            ->call('abrirModal')
            ->set('nombre', 'Ministerio de Salud')
            ->set('sigla', 'MS')
            ->set('paisCodigo', 'AR')
            ->set('activo', true)
            ->call('guardar');

        $this->assertDatabaseHas('entidades_publicas', [
            'nombre' => 'Ministerio de Salud',
            'sigla' => 'MS',
            'pais_codigo' => 'AR',
        ]);
    }

    public function test_create_validates_required_fields(): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)
            ->test(EntidadesPublicas::class)
            ->call('abrirModal')
            ->set('nombre', '')
            ->set('paisCodigo', '')
            ->call('guardar')
            ->assertHasErrors(['nombre', 'paisCodigo']);
    }

    // ─── Edit ─────────────────────────────────────────────────────────────────

    public function test_admin_can_edit(): void
    {
        $admin = $this->createAdmin();
        $entidad = $this->createEntidad();

        Livewire::actingAs($admin)
            ->test(EntidadesPublicas::class)
            ->call('abrirModal', $entidad->id)
            ->set('nombre', 'Ministerio de Defensa')
            ->call('guardar');

        $this->assertDatabaseHas('entidades_publicas', [
            'id' => $entidad->id,
            'nombre' => 'Ministerio de Defensa',
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function test_admin_can_delete(): void
    {
        $admin = $this->createAdmin();
        $entidad = $this->createEntidad();

        Livewire::actingAs($admin)
            ->test(EntidadesPublicas::class)
            ->call('eliminar', $entidad->id);

        $this->assertDatabaseMissing('entidades_publicas', ['id' => $entidad->id]);
    }

    // ─── Toggle Active ────────────────────────────────────────────────────────

    public function test_admin_can_toggle_activo(): void
    {
        $admin = $this->createAdmin();
        $entidad = $this->createEntidad(['activo' => true]);

        Livewire::actingAs($admin)
            ->test(EntidadesPublicas::class)
            ->call('toggleActivo', $entidad->id);

        $this->assertDatabaseHas('entidades_publicas', [
            'id' => $entidad->id,
            'activo' => false,
        ]);
    }

    // ─── Filters ──────────────────────────────────────────────────────────────

    public function test_filters_by_pais(): void
    {
        $admin = $this->createAdmin();
        $this->createPais('AR', 'Argentina');
        $this->createPais('UY', 'Uruguay');
        $this->createEntidad(['pais_codigo' => 'AR', 'nombre' => 'Organismo AR']);
        $this->createEntidad(['pais_codigo' => 'UY', 'nombre' => 'Organismo UY']);

        $component = Livewire::actingAs($admin)
            ->test(EntidadesPublicas::class)
            ->set('filtroPais', 'AR');

        $component->assertSee('Organismo AR');
        $component->assertDontSee('Organismo UY');
    }
}
