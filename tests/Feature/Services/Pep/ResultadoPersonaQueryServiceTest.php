<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Pep;

use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\Pep\DTOs\EventoPepDTO;
use App\Services\Pep\ResultadoPersonaQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for ResultadoPersonaQueryService.
 *
 * These tests use RefreshDatabase with SQLite in-memory.
 * Postgres-only features (ARRAY_AGG, EXPLAIN) are guarded with markTestSkipped.
 *
 * The core grouping logic (COUNT, GROUP BY) works on both drivers.
 * ARRAY_AGG for resultadoIds/sitios is handled via SQLite fallback in the service.
 */
class ResultadoPersonaQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResultadoPersonaQueryService $service;

    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);

        $this->service = app(ResultadoPersonaQueryService::class);

        $this->sitio = SitioWeb::create([
            'url'    => 'https://eldeber.com.bo',
            'nombre' => 'El Deber',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    /**
     * Helper: create a ResultadoScraping row.
     */
    private function crearResultado(
        string $fecha = '2026-04-01 10:00:00',
        ?SitioWeb $sitio = null,
    ): ResultadoScraping {
        return ResultadoScraping::create([
            'url'              => 'https://eldeber.com.bo/nota-' . uniqid(),
            'keyword'          => 'test',
            'sitio_id'         => ($sitio ?? $this->sitio)->id,
            'pais'             => 'BO',
            'fecha_encontrado' => $fecha,
            'relevance_score'  => 80,
            'found_in_title'   => false,
            'leido'            => false,
            'descartado'       => false,
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => true,
            'gemini_categoria' => 'PEP',
        ]);
    }

    /**
     * Helper: create a ResultadoPersona row linked to a ResultadoScraping.
     */
    private function crearPersona(
        ResultadoScraping $resultado,
        string $nombre = 'Juan Pérez',
        ?string $nombreNormalizado = 'Juan Pérez',
        string $evento = 'renuncia',
        string $categoria = 'PEP',
        bool $thresholdPassed = true,
        ?string $cargo = 'Ministro',
    ): ResultadoPersona {
        return ResultadoPersona::create([
            'resultado_scraping_id' => $resultado->id,
            'nombre'                => $nombre,
            'nombre_normalizado'    => $nombreNormalizado,
            'evento'                => $evento,
            'categoria'             => $categoria,
            'threshold_passed'      => $thresholdPassed,
            'cargo'                 => $cargo,
            'confianza'             => 85,
        ]);
    }

    // =========================================================================
    // Spec scenario 1: Same person + evento + day → 1 group
    // =========================================================================

    /**
     * GIVEN 3 resultado_personas rows with same nombre_normalizado, evento, day, threshold_passed=true
     *   linked to 3 different resultados_scraping rows
     * WHEN the panel query runs
     * THEN 1 group is returned with numFuentes = 3 and resultadoIds count = 3
     */
    public function test_same_person_evento_day_produces_one_group(): void
    {
        $r1 = $this->crearResultado('2026-04-01 08:00:00');
        $r2 = $this->crearResultado('2026-04-01 12:00:00');
        $r3 = $this->crearResultado('2026-04-01 18:00:00');

        $this->crearPersona($r1);
        $this->crearPersona($r2);
        $this->crearPersona($r3);

        $result = $this->service->getEventosAgrupados();
        $items  = collect($result->items());

        $this->assertCount(1, $items, 'Three rows for same person+evento+day must produce exactly 1 group');

        /** @var EventoPepDTO $group */
        $group = $items->first();
        $this->assertSame(3, $group->numFuentes, 'numFuentes must equal the count of source rows (3)');
        $this->assertCount(3, $group->resultadoIds, 'resultadoIds must contain all 3 resultado IDs');
        $this->assertSame('Juan Pérez', $group->nombreNormalizado);
        $this->assertSame('renuncia', $group->evento);
    }

    // =========================================================================
    // Spec scenario 2: Same person + evento across 2 days → 2 groups
    // =========================================================================

    public function test_same_person_evento_different_days_produces_two_groups(): void
    {
        $r1 = $this->crearResultado('2026-04-01 10:00:00');
        $r2 = $this->crearResultado('2026-04-02 10:00:00');

        $this->crearPersona($r1, evento: 'renuncia');
        $this->crearPersona($r2, evento: 'renuncia');

        $result = $this->service->getEventosAgrupados();
        $items  = collect($result->items());

        $this->assertCount(2, $items, 'Same person+evento on different days must produce 2 groups');

        $days = $items->map(fn (EventoPepDTO $g) => $g->dia->toDateString())->sort()->values();
        $this->assertSame('2026-04-01', $days[0]);
        $this->assertSame('2026-04-02', $days[1]);
    }

    // =========================================================================
    // Spec scenario 3: Same person, different evento, same day → 2 groups
    // =========================================================================

    public function test_same_person_different_evento_same_day_produces_two_groups(): void
    {
        $r1 = $this->crearResultado('2026-04-01 10:00:00');
        $r2 = $this->crearResultado('2026-04-01 11:00:00');

        $this->crearPersona($r1, evento: 'renuncia');
        $this->crearPersona($r2, evento: 'designacion');

        $result = $this->service->getEventosAgrupados();
        $items  = collect($result->items());

        $this->assertCount(2, $items, 'Same person on the same day with different eventos must produce 2 groups');

        $eventos = $items->map(fn (EventoPepDTO $g) => $g->evento)->sort()->values();
        $this->assertSame('designacion', $eventos[0]);
        $this->assertSame('renuncia', $eventos[1]);
    }

    // =========================================================================
    // Spec scenario 4 & 5: threshold_passed=false and NULL nombre excluded
    // =========================================================================

    public function test_threshold_not_passed_rows_excluded_from_panel(): void
    {
        $r = $this->crearResultado();
        $this->crearPersona($r, thresholdPassed: false);

        $result = $this->service->getEventosAgrupados();

        $this->assertCount(0, collect($result->items()), 'threshold_passed=false rows must be excluded from the panel');
        $this->assertSame(0, $result->total(), 'Total must be 0 when all rows have threshold_passed=false');
    }

    public function test_null_nombre_normalizado_excluded_from_panel(): void
    {
        $r = $this->crearResultado();

        ResultadoPersona::create([
            'resultado_scraping_id' => $r->id,
            'nombre'                => 'Persona sin normalizar',
            'nombre_normalizado'    => null,
            'evento'                => 'renuncia',
            'categoria'             => 'PEP',
            'threshold_passed'      => true,
            'confianza'             => 80,
        ]);

        $result = $this->service->getEventosAgrupados();

        $this->assertCount(0, collect($result->items()), 'Rows with nombre_normalizado=NULL must be excluded from the panel');
    }

    // =========================================================================
    // Filter: categoria
    // =========================================================================

    public function test_filtro_categoria_pep_returns_only_pep_groups(): void
    {
        $r1 = $this->crearResultado('2026-04-01 10:00:00');
        $r2 = $this->crearResultado('2026-04-01 11:00:00');

        $this->crearPersona($r1, nombre: 'Persona PEP', nombreNormalizado: 'Persona PEP', categoria: 'PEP');
        $this->crearPersona($r2, nombre: 'Persona OPI', nombreNormalizado: 'Persona OPI', categoria: 'OPI');

        $result = $this->service->getEventosAgrupados(categoria: 'PEP');
        $items  = collect($result->items());

        $this->assertCount(1, $items, 'Filter categoria=PEP must return only PEP groups');
        $this->assertSame('PEP', $items->first()->categoria);
    }

    // =========================================================================
    // Filter: date range
    // =========================================================================

    public function test_fecha_range_filter_limits_results_to_range(): void
    {
        $r1 = $this->crearResultado('2026-03-01 10:00:00');
        $r2 = $this->crearResultado('2026-04-01 10:00:00');
        $r3 = $this->crearResultado('2026-05-01 10:00:00');

        $this->crearPersona($r1, nombre: 'Persona A', nombreNormalizado: 'Persona A', evento: 'renuncia');
        $this->crearPersona($r2, nombre: 'Persona B', nombreNormalizado: 'Persona B', evento: 'renuncia');
        $this->crearPersona($r3, nombre: 'Persona C', nombreNormalizado: 'Persona C', evento: 'renuncia');

        $result = $this->service->getEventosAgrupados(
            fechaDesde: '2026-03-15',
            fechaHasta: '2026-04-30',
        );
        $items = collect($result->items());

        $this->assertCount(1, $items, 'Date range filter must return only groups within the range');
        $this->assertSame('2026-04-01', $items->first()->dia->toDateString());
    }

    // =========================================================================
    // Filter: mostrarSinClasificar toggle
    // =========================================================================

    public function test_mostrar_sin_clasificar_false_excludes_null_evento(): void
    {
        $r1 = $this->crearResultado('2026-04-01 10:00:00');
        $r2 = $this->crearResultado('2026-04-01 11:00:00');

        $this->crearPersona($r1, nombre: 'Persona Con Evento', nombreNormalizado: 'Persona Con Evento', evento: 'renuncia');
        ResultadoPersona::create([
            'resultado_scraping_id' => $r2->id,
            'nombre'                => 'Persona Sin Evento',
            'nombre_normalizado'    => 'Persona Sin Evento',
            'evento'                => null,
            'categoria'             => 'PEP',
            'threshold_passed'      => true,
            'confianza'             => 80,
        ]);

        $result = $this->service->getEventosAgrupados(mostrarSinClasificar: false);
        $items  = collect($result->items());

        $this->assertCount(1, $items, 'mostrarSinClasificar=false must exclude NULL evento groups');
        $this->assertSame('renuncia', $items->first()->evento);
    }

    public function test_mostrar_sin_clasificar_true_includes_null_evento(): void
    {
        $r1 = $this->crearResultado('2026-04-01 10:00:00');
        $r2 = $this->crearResultado('2026-04-01 11:00:00');

        $this->crearPersona($r1, nombre: 'Persona Con Evento', nombreNormalizado: 'Persona Con Evento', evento: 'renuncia');
        ResultadoPersona::create([
            'resultado_scraping_id' => $r2->id,
            'nombre'                => 'Persona Sin Evento',
            'nombre_normalizado'    => 'Persona Sin Evento',
            'evento'                => null,
            'categoria'             => 'PEP',
            'threshold_passed'      => true,
            'confianza'             => 80,
        ]);

        $result = $this->service->getEventosAgrupados(mostrarSinClasificar: true);
        $items  = collect($result->items());

        $this->assertCount(2, $items, 'mostrarSinClasificar=true must include NULL evento groups');

        $nullEventoGroups = $items->filter(fn (EventoPepDTO $g) => $g->evento === null);
        $this->assertCount(1, $nullEventoGroups, 'One group must have null evento');
    }

    // =========================================================================
    // Filter: empty filters return all eligible groups
    // =========================================================================

    public function test_empty_filters_return_all_eligible_groups(): void
    {
        $r1 = $this->crearResultado('2026-04-01 10:00:00');
        $r2 = $this->crearResultado('2026-04-02 10:00:00');
        $r3 = $this->crearResultado('2026-04-03 10:00:00');

        $this->crearPersona($r1, nombre: 'Alpha', nombreNormalizado: 'Alpha', categoria: 'PEP');
        $this->crearPersona($r2, nombre: 'Beta', nombreNormalizado: 'Beta', categoria: 'OPI', evento: 'designacion');
        $this->crearPersona($r3, nombre: 'Gamma', nombreNormalizado: 'Gamma', categoria: 'PEP', evento: 'crimen');

        // threshold_passed=false — must not appear
        $rExcluido = $this->crearResultado();
        $this->crearPersona($rExcluido, thresholdPassed: false);

        $result = $this->service->getEventosAgrupados();
        $items  = collect($result->items());

        $this->assertCount(3, $items, 'Empty filters must return all 3 eligible groups (excluded row must not appear)');
    }

    // =========================================================================
    // Cargo: most recent, not alphabetical
    // =========================================================================

    public function test_cargo_returns_most_recent_not_alphabetical(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cargo most-recent test requires PostgreSQL (DISTINCT ON / ORDER BY subquery)');
        }

        // Two rows, same person+evento+day
        // "Director" is alphabetically later than "Ministro" but has older fecha_encontrado
        $r1 = $this->crearResultado('2026-04-01 08:00:00'); // older
        $r2 = $this->crearResultado('2026-04-01 18:00:00'); // newer

        ResultadoPersona::create([
            'resultado_scraping_id' => $r1->id,
            'nombre'                => 'Juan Pérez',
            'nombre_normalizado'    => 'Juan Pérez',
            'evento'                => 'renuncia',
            'categoria'             => 'PEP',
            'threshold_passed'      => true,
            'cargo'                 => 'Director', // older, alphabetically later
            'confianza'             => 85,
        ]);
        ResultadoPersona::create([
            'resultado_scraping_id' => $r2->id,
            'nombre'                => 'Juan Pérez',
            'nombre_normalizado'    => 'Juan Pérez',
            'evento'                => 'renuncia',
            'categoria'             => 'PEP',
            'threshold_passed'      => true,
            'cargo'                 => 'Ministro', // newer, alphabetically earlier
            'confianza'             => 85,
        ]);

        $result = $this->service->getEventosAgrupados();
        $items  = collect($result->items());

        $this->assertCount(1, $items);
        $this->assertSame('Ministro', $items->first()->cargo, 'cargo must be the most recent one (Ministro, newer fecha_encontrado), not alphabetical (Director)');
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    public function test_pagination_returns_correct_total_with_group_by(): void
    {
        // Create 15 unique groups (different person names, same evento+day)
        for ($i = 1; $i <= 15; $i++) {
            $day = '2026-04-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $r = $this->crearResultado("{$day} 10:00:00");
            $this->crearPersona(
                $r,
                nombre: "Persona {$i}",
                nombreNormalizado: "Persona {$i}",
                evento: 'renuncia',
            );
        }

        $result = $this->service->getEventosAgrupados(perPage: 10);

        $this->assertSame(15, $result->total(), 'Paginator total must reflect the number of groups (15), not rows');
        $this->assertCount(10, collect($result->items()), 'First page must contain 10 groups');
        $this->assertSame(2, $result->lastPage(), 'Must have 2 pages for 15 groups at perPage=10');
    }

    // =========================================================================
    // Index usage (Postgres only)
    // =========================================================================

    public function test_grouping_query_plan_uses_partial_index(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('EXPLAIN plan test requires PostgreSQL');
        }

        $plan = DB::select("EXPLAIN SELECT 1 FROM resultado_personas WHERE threshold_passed = true AND nombre_normalizado IS NOT NULL");
        $planText = collect($plan)->map(fn ($row) => array_values((array) $row)[0])->implode("\n");

        $this->assertStringContainsString(
            'resultado_personas_grouping_idx',
            $planText,
            'Query plan must reference the partial index resultado_personas_grouping_idx'
        );
    }
}
