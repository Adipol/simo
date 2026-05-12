<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\EntidadPublica;
use Database\Seeders\EntidadesPublicasBoliviaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntidadesPublicasBoliviaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_inserts_records(): void
    {
        $this->seed(EntidadesPublicasBoliviaSeeder::class);

        $this->assertGreaterThan(200, EntidadPublica::count());
    }

    public function test_all_records_have_pais_codigo_bo(): void
    {
        $this->seed(EntidadesPublicasBoliviaSeeder::class);

        $wrongPais = EntidadPublica::where('pais_codigo', '!=', 'BO')->count();

        $this->assertSame(0, $wrongPais);
    }

    public function test_seeder_is_idempotent_no_duplicates_on_double_run(): void
    {
        $this->seed(EntidadesPublicasBoliviaSeeder::class);
        $firstCount = EntidadPublica::count();

        $this->seed(EntidadesPublicasBoliviaSeeder::class);
        $secondCount = EntidadPublica::count();

        $this->assertSame($firstCount, $secondCount);
    }

    public function test_known_entities_exist_after_seeding(): void
    {
        $this->seed(EntidadesPublicasBoliviaSeeder::class);

        $this->assertDatabaseHas('entidades_publicas', ['pais_codigo' => 'BO', 'nombre' => 'Ministerio de Economía y Finanzas Públicas']);
        $this->assertDatabaseHas('entidades_publicas', ['pais_codigo' => 'BO', 'nombre' => 'Ministerio de Gobierno']);
        $this->assertDatabaseHas('entidades_publicas', ['pais_codigo' => 'BO', 'nombre' => 'Banco Central de Bolivia']);
    }

    public function test_all_records_are_active(): void
    {
        $this->seed(EntidadesPublicasBoliviaSeeder::class);

        $inactive = EntidadPublica::where('activo', false)->count();

        $this->assertSame(0, $inactive);
    }
}
