<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\FamiliaLema;
use Database\Seeders\FamiliasLemasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamiliasLemasSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_exactly_33_families(): void
    {
        $this->seed(FamiliasLemasSeeder::class);

        $this->assertSame(33, FamiliaLema::count());
    }

    public function test_seeder_is_idempotent_running_twice_keeps_33(): void
    {
        $this->seed(FamiliasLemasSeeder::class);
        $this->seed(FamiliasLemasSeeder::class);

        $this->assertSame(33, FamiliaLema::count());
    }

    public function test_seeder_creates_9_designacion_families(): void
    {
        $this->seed(FamiliasLemasSeeder::class);

        $this->assertSame(9, FamiliaLema::byCategoria('PEP-designacion')->count());
    }

    public function test_seeder_creates_8_renuncia_families(): void
    {
        $this->seed(FamiliasLemasSeeder::class);

        $this->assertSame(8, FamiliaLema::byCategoria('PEP-renuncia')->count());
    }

    public function test_seeder_creates_16_crimen_families(): void
    {
        $this->seed(FamiliasLemasSeeder::class);

        $this->assertSame(16, FamiliaLema::byCategoria('OPI-crimen')->count());
    }
}
