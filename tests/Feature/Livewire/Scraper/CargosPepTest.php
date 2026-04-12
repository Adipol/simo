<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Enums\EntidadTipo;
use App\Livewire\Scraper\CargosPep;
use App\Models\CargoPep;
use App\Models\Pais;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CargosPepTest extends TestCase
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

    private function createCargo(array $overrides = []): CargoPep
    {
        $pais = $this->createPais();

        return CargoPep::create(array_merge([
            'pais_codigo' => 'AR',
            'nombre' => 'Presidente',
            'categoria' => 'Ejecutivo',
            'entidad_tipo' => EntidadTipo::Publica->value,
            'activo' => true,
        ], $overrides));
    }

    // ─── Route Protection ─────────────────────────────────────────────────────

    public function test_admin_can_access_cargos_pep_page(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->get('/scraper/cargos-pep');

        $response->assertOk();
    }

    public function test_operador_cannot_access_cargos_pep_page(): void
    {
        $operador = $this->createOperador();

        $response = $this->actingAs($operador)->get('/scraper/cargos-pep');

        $response->assertForbidden();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_admin_can_create_cargo(): void
    {
        $admin = $this->createAdmin();
        $this->createPais('AR', 'Argentina');

        Livewire::actingAs($admin)
            ->test(CargosPep::class)
            ->call('abrirModal')
            ->set('nombre', 'Senador Nacional')
            ->set('paisCodigo', 'AR')
            ->set('categoria', 'Legislativo')
            ->set('entidadTipo', EntidadTipo::Publica->value)
            ->set('activo', true)
            ->call('guardar');

        $this->assertDatabaseHas('cargos_pep', [
            'nombre' => 'Senador Nacional',
            'pais_codigo' => 'AR',
            'categoria' => 'Legislativo',
        ]);
    }

    public function test_create_validates_required_fields(): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)
            ->test(CargosPep::class)
            ->call('abrirModal')
            ->set('nombre', '')
            ->set('paisCodigo', '')
            ->set('categoria', '')
            ->call('guardar')
            ->assertHasErrors(['nombre', 'paisCodigo', 'categoria']);
    }

    // ─── Edit ─────────────────────────────────────────────────────────────────

    public function test_admin_can_edit_cargo(): void
    {
        $admin = $this->createAdmin();
        $cargo = $this->createCargo();

        Livewire::actingAs($admin)
            ->test(CargosPep::class)
            ->call('abrirModal', $cargo->id)
            ->set('nombre', 'Vice Presidente')
            ->call('guardar');

        $this->assertDatabaseHas('cargos_pep', [
            'id' => $cargo->id,
            'nombre' => 'Vice Presidente',
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function test_admin_can_delete_cargo(): void
    {
        $admin = $this->createAdmin();
        $cargo = $this->createCargo();

        Livewire::actingAs($admin)
            ->test(CargosPep::class)
            ->call('eliminar', $cargo->id);

        $this->assertDatabaseMissing('cargos_pep', ['id' => $cargo->id]);
    }

    // ─── Toggle Active ────────────────────────────────────────────────────────

    public function test_admin_can_toggle_activo(): void
    {
        $admin = $this->createAdmin();
        $cargo = $this->createCargo(['activo' => true]);

        Livewire::actingAs($admin)
            ->test(CargosPep::class)
            ->call('toggleActivo', $cargo->id);

        $this->assertDatabaseHas('cargos_pep', [
            'id' => $cargo->id,
            'activo' => false,
        ]);
    }

    // ─── Filters ──────────────────────────────────────────────────────────────

    public function test_filters_by_pais(): void
    {
        $admin = $this->createAdmin();
        $this->createPais('AR', 'Argentina');
        $this->createPais('UY', 'Uruguay');
        $this->createCargo(['pais_codigo' => 'AR', 'nombre' => 'Presidente AR']);
        $this->createCargo(['pais_codigo' => 'UY', 'nombre' => 'Presidente UY']);

        $component = Livewire::actingAs($admin)
            ->test(CargosPep::class)
            ->set('filtroPais', 'AR');

        $component->assertSee('Presidente AR');
        $component->assertDontSee('Presidente UY');
    }

    public function test_filters_by_entidad_tipo(): void
    {
        $admin = $this->createAdmin();
        $this->createPais('AR', 'Argentina');
        $this->createCargo(['nombre' => 'Cargo Publico', 'entidad_tipo' => EntidadTipo::Publica->value]);
        $this->createCargo(['nombre' => 'Cargo Todas', 'entidad_tipo' => EntidadTipo::Todas->value]);

        $component = Livewire::actingAs($admin)
            ->test(CargosPep::class)
            ->set('filtroEntidadTipo', EntidadTipo::Publica->value);

        $component->assertSee('Cargo Publico');
        $component->assertDontSee('Cargo Todas');
    }
}
