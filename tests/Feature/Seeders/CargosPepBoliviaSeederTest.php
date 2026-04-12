<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\CargoPep;
use Database\Seeders\CargosPepBoliviaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargosPepBoliviaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_inserts_exactly_97_records(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);

        $this->assertSame(97, CargoPep::count());
    }

    public function test_all_records_have_pais_codigo_bo(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);

        $wrongPais = CargoPep::where('pais_codigo', '!=', 'BO')->count();

        $this->assertSame(0, $wrongPais);
    }

    public function test_all_records_are_active(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);

        $inactive = CargoPep::where('activo', false)->count();

        $this->assertSame(0, $inactive);
    }

    public function test_seeder_is_idempotent_no_duplicates_on_double_run(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);
        $this->seed(CargosPepBoliviaSeeder::class);

        $this->assertSame(97, CargoPep::count());
    }

    public function test_entidad_tipo_values_are_valid(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);

        $validTypes = ['todas', 'publica', 'ambas'];
        $invalidCount = CargoPep::whereNotIn('entidad_tipo', $validTypes)->count();

        $this->assertSame(0, $invalidCount);
    }

    public function test_todas_group_has_7_records(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);

        $count = CargoPep::where('entidad_tipo', 'todas')->count();

        $this->assertSame(7, $count);
    }

    public function test_ambas_group_has_15_records(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);

        $count = CargoPep::where('entidad_tipo', 'ambas')->count();

        $this->assertSame(15, $count);
    }

    public function test_publica_group_has_75_records(): void
    {
        $this->seed(CargosPepBoliviaSeeder::class);

        $count = CargoPep::where('entidad_tipo', 'publica')->count();

        $this->assertSame(75, $count);
    }
}
