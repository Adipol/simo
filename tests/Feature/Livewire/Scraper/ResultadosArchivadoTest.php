<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ResultadosArchivadoTest extends TestCase
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

    private function createResultado(array $attrs = []): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://test.com/article-'.uniqid(),
            'keyword' => 'test keyword',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => false,
        ], $attrs));
    }

    // ─── 1. Scopes ───────────────────────────────────────────────────────────

    public function test_scope_no_archivado_excluye_archivados(): void
    {
        $noArchivado = $this->createResultado(['archivado_at' => null]);
        $archivado = $this->createResultado(['archivado_at' => now()]);

        $ids = ResultadoScraping::noArchivado()->pluck('id');

        $this->assertTrue($ids->contains($noArchivado->id));
        $this->assertFalse($ids->contains($archivado->id));
    }

    public function test_scope_archivado_incluye_solo_archivados(): void
    {
        $noArchivado = $this->createResultado(['archivado_at' => null]);
        $archivado = $this->createResultado(['archivado_at' => now()]);

        $ids = ResultadoScraping::archivado()->pluck('id');

        $this->assertFalse($ids->contains($noArchivado->id));
        $this->assertTrue($ids->contains($archivado->id));
    }

    // ─── 2. Filter — default hides archived ──────────────────────────────────

    public function test_filtro_archivado_default_oculta_archivados(): void
    {
        $admin = $this->createAdmin();
        $visible = $this->createResultado(['archivado_at' => null, 'keyword' => 'keyword-visible-default']);
        $archivado = $this->createResultado(['archivado_at' => now(), 'keyword' => 'keyword-archivado-default']);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->assertSet('filtroArchivado', '0')
            ->assertSee('keyword-visible-default')
            ->assertDontSee('keyword-archivado-default');
    }

    public function test_filtro_archivado_1_muestra_solo_archivados(): void
    {
        $admin = $this->createAdmin();
        $visible = $this->createResultado(['archivado_at' => null, 'keyword' => 'keyword-activo']);
        $archivado = $this->createResultado(['archivado_at' => now(), 'keyword' => 'keyword-archivado']);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->set('filtroArchivado', '1')
            ->assertDontSee('keyword-activo')
            ->assertSee('keyword-archivado');
    }

    // ─── 3. archivar() ────────────────────────────────────────────────────────

    public function test_archivar_sets_archivado_at_and_leido(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado(['leido' => false, 'archivado_at' => null]);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('archivar', $resultado->id);

        $resultado->refresh();

        $this->assertNotNull($resultado->archivado_at);
        $this->assertTrue($resultado->leido);
    }

    public function test_archivar_requiere_permiso_gestionar_resultados(): void
    {
        $operador = $this->createOperador();
        $resultado = $this->createResultado();

        Livewire::actingAs($operador)
            ->test(Resultados::class)
            ->call('archivar', $resultado->id)
            ->assertForbidden();
    }

    // ─── 4. desarchivar() ────────────────────────────────────────────────────

    public function test_desarchivar_limpia_archivado_at(): void
    {
        $admin = $this->createAdmin();
        $resultado = $this->createResultado(['archivado_at' => now()]);

        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('desarchivar', $resultado->id);

        $resultado->refresh();

        $this->assertNull($resultado->archivado_at);
    }

    public function test_desarchivar_requiere_permiso_gestionar_resultados(): void
    {
        $operador = $this->createOperador();
        $resultado = $this->createResultado(['archivado_at' => now()]);

        Livewire::actingAs($operador)
            ->test(Resultados::class)
            ->call('desarchivar', $resultado->id)
            ->assertForbidden();
    }

    // ─── 5. updatingFiltroArchivado resets page ──────────────────────────────

    public function test_cambiar_filtro_archivado_resetea_paginacion(): void
    {
        $admin = $this->createAdmin();

        // Create 30 results to have multiple pages
        for ($i = 0; $i < 30; $i++) {
            $this->createResultado(['keyword' => "kw-archivado-{$i}"]);
        }

        // After changing filter, component renders without error (resetPage is called by updatingFiltroArchivado)
        Livewire::actingAs($admin)
            ->test(Resultados::class)
            ->call('nextPage')
            ->set('filtroArchivado', '1')
            ->assertOk();
    }
}
