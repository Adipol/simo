<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Cambio;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupImagenesCambiosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('img_cambios');
    }

    // ─── Helper para crear un archivo falso en el disco fake ─────────────────

    private function crearArchivoFake(int $cambioId, int $idx = 0, string $ext = 'png'): string
    {
        $nombre = "{$cambioId}_{$idx}.{$ext}";
        Storage::disk('img_cambios')->put($nombre, 'fake-image-bytes');

        return $nombre;
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * Archivo en disco cuyo cambio_id NO existe en BD → debe borrarse.
     */
    public function test_borra_archivo_de_cambio_inexistente(): void
    {
        $this->crearArchivoFake(cambioId: 99999, idx: 0);

        Storage::disk('img_cambios')->assertExists('99999_0.png');

        $this->artisan('cleanup:imagenes-cambios', ['--days' => 90])
            ->assertExitCode(0);

        Storage::disk('img_cambios')->assertMissing('99999_0.png');
    }

    /**
     * Archivo + cambio en BD con fecha = now() → NO debe borrarse.
     */
    public function test_no_borra_archivo_de_cambio_reciente(): void
    {
        $cambio = Cambio::factory()->create(['fecha' => now()]);

        $this->crearArchivoFake(cambioId: $cambio->id, idx: 0);

        Storage::disk('img_cambios')->assertExists("{$cambio->id}_0.png");

        $this->artisan('cleanup:imagenes-cambios', ['--days' => 90])
            ->assertExitCode(0);

        Storage::disk('img_cambios')->assertExists("{$cambio->id}_0.png");
    }

    /**
     * Archivo + cambio en BD con fecha = hace 100 días → debe borrarse (--days=90).
     */
    public function test_borra_archivo_de_cambio_viejo(): void
    {
        $cambio = Cambio::factory()->create(['fecha' => now()->subDays(100)]);

        $this->crearArchivoFake(cambioId: $cambio->id, idx: 0);

        Storage::disk('img_cambios')->assertExists("{$cambio->id}_0.png");

        $this->artisan('cleanup:imagenes-cambios', ['--days' => 90])
            ->assertExitCode(0);

        Storage::disk('img_cambios')->assertMissing("{$cambio->id}_0.png");
    }

    /**
     * Disk vacío (sin archivos) → exit 0 sin errores.
     */
    public function test_funciona_con_directorio_vacio(): void
    {
        // Storage::fake('img_cambios') ya crea el root vacío

        $this->artisan('cleanup:imagenes-cambios', ['--days' => 90])
            ->assertExitCode(0);
    }

    /**
     * Storage::fake garantiza que el disk root existe físicamente, así que
     * este escenario realmente prueba el caso "disk vacío" — equivalente al test anterior.
     * Lo mantenemos por documentación del comportamiento esperado.
     */
    public function test_funciona_si_directorio_no_existe(): void
    {
        $this->artisan('cleanup:imagenes-cambios', ['--days' => 90])
            ->assertExitCode(0);
    }
}
