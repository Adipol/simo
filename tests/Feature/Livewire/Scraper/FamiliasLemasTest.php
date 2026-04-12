<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\FamiliasLemas;
use App\Models\FamiliaLema;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FamiliasLemasTest extends TestCase
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

    private function createSupervisor(): User
    {
        $user = User::factory()->create(['activo' => true]);
        $user->assignRole('supervisor');

        return $user;
    }

    private function createFamilia(array $overrides = []): FamiliaLema
    {
        return FamiliaLema::create(array_merge([
            'raiz' => 'designar',
            'variantes' => ['designar', 'designación', 'designado'],
            'categoria' => 'designacion',
            'activo' => true,
        ], $overrides));
    }

    // ─── Route Protection ─────────────────────────────────────────────────────

    public function test_unauthenticated_user_gets_redirected_from_familias_lemas(): void
    {
        $response = $this->get('/scraper/familias-lemas');

        $response->assertRedirect(route('login'));
    }

    public function test_supervisor_cannot_access_familias_lemas(): void
    {
        $supervisor = $this->createSupervisor();

        $response = $this->actingAs($supervisor)->get('/scraper/familias-lemas');

        $response->assertForbidden();
    }

    public function test_admin_can_access_familias_lemas(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->get('/scraper/familias-lemas');

        $response->assertOk();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_admin_can_create_family_with_valid_data(): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)
            ->test(FamiliasLemas::class)
            ->call('abrirModal')
            ->set('raiz', 'testar')
            ->set('variantesRaw', "testar\ntestando\ntestado")
            ->set('categoria', 'crimen')
            ->call('guardar');

        $this->assertDatabaseHas('familias_lemas', [
            'raiz' => 'testar',
            'categoria' => 'crimen',
        ]);
    }

    public function test_create_with_duplicate_raiz_fails_validation(): void
    {
        $admin = $this->createAdmin();
        $this->createFamilia(['raiz' => 'designar']);

        Livewire::actingAs($admin)
            ->test(FamiliasLemas::class)
            ->call('abrirModal')
            ->set('raiz', 'designar')
            ->set('variantesRaw', 'designar')
            ->set('categoria', 'designacion')
            ->call('guardar')
            ->assertHasErrors(['raiz']);
    }

    public function test_create_with_empty_variantes_fails_validation(): void
    {
        $admin = $this->createAdmin();

        Livewire::actingAs($admin)
            ->test(FamiliasLemas::class)
            ->call('abrirModal')
            ->set('raiz', 'testar')
            ->set('variantesRaw', '')
            ->set('categoria', 'crimen')
            ->call('guardar')
            ->assertHasErrors(['variantesRaw']);
    }

    // ─── Edit ─────────────────────────────────────────────────────────────────

    public function test_admin_can_edit_existing_family(): void
    {
        $admin = $this->createAdmin();
        $familia = $this->createFamilia();

        Livewire::actingAs($admin)
            ->test(FamiliasLemas::class)
            ->call('abrirModal', $familia->id)
            ->set('raiz', 'designar-modificado')
            ->set('variantesRaw', "designar-modificado\ndesignación")
            ->set('categoria', 'designacion')
            ->call('guardar');

        $this->assertDatabaseHas('familias_lemas', [
            'id' => $familia->id,
            'raiz' => 'designar-modificado',
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function test_admin_can_delete_family(): void
    {
        $admin = $this->createAdmin();
        $familia = $this->createFamilia();

        Livewire::actingAs($admin)
            ->test(FamiliasLemas::class)
            ->call('eliminar', $familia->id);

        $this->assertDatabaseMissing('familias_lemas', ['id' => $familia->id]);
    }

    // ─── Toggle Active ────────────────────────────────────────────────────────

    public function test_toggle_activo_flips_active_flag(): void
    {
        $admin = $this->createAdmin();
        $familia = $this->createFamilia(['activo' => true]);

        Livewire::actingAs($admin)
            ->test(FamiliasLemas::class)
            ->call('toggleActivo', $familia->id);

        $this->assertDatabaseHas('familias_lemas', [
            'id' => $familia->id,
            'activo' => false,
        ]);
    }

    // ─── Filter by category ───────────────────────────────────────────────────

    public function test_filter_by_categoria_returns_correct_families(): void
    {
        $admin = $this->createAdmin();
        $this->createFamilia(['raiz' => 'designar', 'categoria' => 'designacion']);
        $this->createFamilia(['raiz' => 'renunciar', 'categoria' => 'renuncia']);
        $this->createFamilia(['raiz' => 'detener', 'categoria' => 'crimen']);

        $component = Livewire::actingAs($admin)
            ->test(FamiliasLemas::class)
            ->set('filtroCategoria', 'designacion');

        $component->assertSee('designar');
        $component->assertDontSee('renunciar');
    }
}
