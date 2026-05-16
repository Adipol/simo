<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CambioJsonScopesTest extends TestCase
{
    use RefreshDatabase;

    private Fuente $fuente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuente = Fuente::factory()->create();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeCambio(array $overrides = []): Cambio
    {
        return Cambio::factory()->create(array_merge([
            'fuente_id' => $this->fuente->id,
        ], $overrides));
    }

    private function withPersonas(array $extra = []): array
    {
        return array_merge([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => 'Juan Pérez',
                'persona_removida' => null,
                'riesgo' => 'alto',
            ],
        ], $extra);
    }

    private function withNoPersonas(array $extra = []): array
    {
        return array_merge([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => null,
                'riesgo' => 'bajo',
            ],
        ], $extra);
    }

    private function withRemovedPersona(array $extra = []): array
    {
        return array_merge([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_nueva' => null,
                'persona_removida' => 'María García',
                'riesgo' => 'medio',
            ],
        ], $extra);
    }

    // =========================================================================
    // scopeConPersona
    // =========================================================================

    /**
     * scopeConPersona includes a Cambio where Gemini detected persona_nueva.
     */
    public function test_scope_con_persona_includes_cambio_with_persona_nueva(): void
    {
        $withPersona = $this->makeCambio($this->withPersonas());
        $this->makeCambio($this->withNoPersonas());

        $ids = Cambio::conPersona()->pluck('id')->all();

        $this->assertContains($withPersona->id, $ids);
        $this->assertCount(1, $ids);
    }

    /**
     * scopeConPersona includes a Cambio where Gemini detected persona_removida.
     */
    public function test_scope_con_persona_includes_cambio_with_persona_removida(): void
    {
        $withRemovida = $this->makeCambio($this->withRemovedPersona());
        $this->makeCambio($this->withNoPersonas());

        $ids = Cambio::conPersona()->pluck('id')->all();

        $this->assertContains($withRemovida->id, $ids);
        $this->assertCount(1, $ids);
    }

    /**
     * scopeConPersona excludes Cambio where Gemini analyzed but found no personas.
     */
    public function test_scope_con_persona_excludes_cambio_with_null_personas(): void
    {
        $this->makeCambio($this->withNoPersonas());

        $count = Cambio::conPersona()->count();

        $this->assertSame(0, $count);
    }

    // =========================================================================
    // scopeSinPersona
    // =========================================================================

    /**
     * scopeSinPersona excludes Cambio with persona entries.
     */
    public function test_scope_sin_persona_excludes_cambio_with_personas(): void
    {
        $this->makeCambio($this->withPersonas());
        $this->makeCambio($this->withRemovedPersona());

        $count = Cambio::sinPersona()->count();

        $this->assertSame(0, $count);
    }

    /**
     * scopeSinPersona includes Cambio where Gemini analyzed and found no personas.
     */
    public function test_scope_sin_persona_includes_cambio_with_null_personas(): void
    {
        $noPersona = $this->makeCambio($this->withNoPersonas());
        $this->makeCambio($this->withPersonas());

        $ids = Cambio::sinPersona()->pluck('id')->all();

        $this->assertContains($noPersona->id, $ids);
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // scopeConRiesgo
    // =========================================================================

    /**
     * scopeConRiesgo includes Cambio with matching riesgo.
     */
    public function test_scope_con_riesgo_includes_cambio_with_matching_riesgo(): void
    {
        $alto = $this->makeCambio($this->withPersonas()); // riesgo = alto
        $this->makeCambio($this->withNoPersonas());       // riesgo = bajo

        $ids = Cambio::conRiesgo('alto')->pluck('id')->all();

        $this->assertContains($alto->id, $ids);
        $this->assertCount(1, $ids);
    }

    /**
     * scopeConRiesgo excludes Cambio with different riesgo.
     */
    public function test_scope_con_riesgo_excludes_cambio_without_matching_riesgo(): void
    {
        $this->makeCambio($this->withNoPersonas()); // riesgo = bajo

        $count = Cambio::conRiesgo('alto')->count();

        $this->assertSame(0, $count);
    }

    /**
     * scopeConRiesgo returns both alto and medio when called separately.
     */
    public function test_scope_con_riesgo_filters_correctly_across_multiple_levels(): void
    {
        $this->makeCambio($this->withPersonas());       // riesgo = alto
        $this->makeCambio($this->withRemovedPersona()); // riesgo = medio
        $this->makeCambio($this->withNoPersonas());     // riesgo = bajo

        $this->assertSame(1, Cambio::conRiesgo('alto')->count());
        $this->assertSame(1, Cambio::conRiesgo('medio')->count());
        $this->assertSame(1, Cambio::conRiesgo('bajo')->count());
    }

    // =========================================================================
    // Unknown driver guard — REQ-1 coverage
    // =========================================================================

    /**
     * jsonExtract lanza RuntimeException cuando el driver no es pgsql ni sqlite.
     *
     * Coverage test: the default branch of the match in jsonExtract() MUST throw.
     * Closes the REQ-1 "unknown driver" spec scenario for this helper.
     * scopeConPersona is used as the trigger because it calls jsonExtract twice
     * (persona_nueva + persona_removida), making it the most representative path.
     */
    public function test_it_throws_on_unknown_driver_for_json_extract(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mysql/');

        DB::shouldReceive('getDriverName')->andReturn('mysql');

        // scopeConPersona invokes jsonExtract internally.
        // The exception fires when building the whereRaw expression, before any DB hit.
        Cambio::conPersona()->toSql();
    }
}
