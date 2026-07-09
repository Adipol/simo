<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Pais;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the default seed of the `paises` table
 * (0001_01_01_000001_create_paises_table.php).
 *
 * Only Bolivia ships active by default. The scraper iterates active countries
 * (get_paises_activos WHERE activo IS TRUE); a country that is active but has no
 * sites configured produces empty "0s" runs in log_scripts (Estado de Scripts
 * noise). Seeding the not-yet-used countries inactive prevents that on a fresh
 * install / migrate:fresh, while keeping the rows available for future use.
 */
class PaisesSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_bolivia_is_active_by_default(): void
    {
        $activos = Pais::where('activo', true)
            ->orderBy('codigo')
            ->pluck('codigo')
            ->all();

        $this->assertSame(['BO'], $activos);
    }

    public function test_all_six_countries_are_still_seeded(): void
    {
        // The other countries must still EXIST (for future multi-country use);
        // they are only inactive by default, never deleted.
        $this->assertSame(6, Pais::count());
    }

    public function test_non_bolivia_countries_are_inactive_by_default(): void
    {
        $activeNonBo = Pais::where('codigo', '!=', 'BO')
            ->where('activo', true)
            ->count();

        $this->assertSame(0, $activeNonBo);
    }
}
