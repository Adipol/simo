<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CambioMultimodalScopeTest extends TestCase
{
    use RefreshDatabase;

    private Fuente $fuente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuente = Fuente::factory()->create();
    }

    /**
     * scopeMultimodal includes a Cambio row that has two image entries.
     */
    public function test_scope_multimodal_includes_row_with_two_images(): void
    {
        Cambio::factory()->create([
            'fuente_id' => $this->fuente->id,
            'imagenes_cambio_json' => [['url' => 'a'], ['url' => 'b']],
        ]);

        $count = Cambio::multimodal()->count();

        $this->assertSame(1, $count);
    }

    /**
     * scopeMultimodal excludes a Cambio row with an empty JSON array.
     */
    public function test_scope_multimodal_excludes_row_with_empty_array(): void
    {
        Cambio::factory()->create([
            'fuente_id' => $this->fuente->id,
            'imagenes_cambio_json' => [],
        ]);

        $count = Cambio::multimodal()->count();

        $this->assertSame(0, $count);
    }

    /**
     * scopeMultimodal excludes a Cambio row with null JSON.
     */
    public function test_scope_multimodal_excludes_row_with_null_json(): void
    {
        Cambio::factory()->create([
            'fuente_id' => $this->fuente->id,
            'imagenes_cambio_json' => null,
        ]);

        $count = Cambio::multimodal()->count();

        $this->assertSame(0, $count);
    }

    /**
     * scopeMultimodal includes only rows with images among mixed data.
     */
    public function test_scope_multimodal_filters_correctly_with_mixed_rows(): void
    {
        // One with images
        Cambio::factory()->create([
            'fuente_id' => $this->fuente->id,
            'imagenes_cambio_json' => [['url' => 'img1']],
        ]);

        // One with empty array
        Cambio::factory()->create([
            'fuente_id' => $this->fuente->id,
            'imagenes_cambio_json' => [],
        ]);

        // One with null
        Cambio::factory()->create([
            'fuente_id' => $this->fuente->id,
            'imagenes_cambio_json' => null,
        ]);

        $results = Cambio::multimodal()->get();

        $this->assertCount(1, $results);
        $this->assertNotNull($results->first()->imagenes_cambio_json);
    }

    /**
     * jsonArrayLength lanza RuntimeException cuando el driver no es pgsql ni sqlite.
     *
     * Coverage test: the default branch of the match in jsonArrayLength() MUST throw.
     * Closes the REQ-1 "unknown driver" spec scenario for this helper.
     */
    public function test_it_throws_on_unknown_driver_for_json_array_length(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mysql/');

        DB::shouldReceive('getDriverName')->andReturn('mysql');

        // scopeMultimodal invokes jsonArrayLength internally.
        // The exception fires when building the whereRaw expression, before hitting the DB.
        Cambio::multimodal()->toSql();
    }
}
