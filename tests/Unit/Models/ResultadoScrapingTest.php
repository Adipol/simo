<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultadoScrapingTest extends TestCase
{
    use RefreshDatabase;

    private function createResultado(array $overrides = []): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://test.com/article',
            'keyword' => 'test',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre' => 'Juan Perez',
            'gemini_cargo' => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo' => 'Es PEP',
        ], $overrides));
    }

    // =========================================================================
    // P3.T7 — RED tests: secondaries() HasMany relation
    // =========================================================================

    /**
     * P3.T7 — secondaries() returns empty HasMany when no children exist.
     */
    public function test_secondaries_relation_returns_has_many(): void
    {
        $primary = ResultadoScraping::factory()->create(['secundario_de' => null]);

        $secondaries = $primary->secondaries;

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $secondaries);
        $this->assertTrue($secondaries->isEmpty(), 'Primary with no children must return empty secondaries collection');
    }

    /**
     * P3.T7 — When B has secundario_de = A.id, B->primary->id === A.id.
     */
    public function test_primary_relation_returns_belongs_to(): void
    {
        $primary = ResultadoScraping::factory()->create(['secundario_de' => null]);
        $secondary = ResultadoScraping::factory()->create(['secundario_de' => $primary->id]);

        $this->assertNotNull($secondary->primary, 'Secondary article must have a primary relation');
        $this->assertSame($primary->id, $secondary->primary->id, 'secondary->primary->id must equal the primary article id');
    }

    /**
     * P3.T7 — A->secondaries contains B when B->secundario_de = A.id.
     */
    public function test_secondaries_contains_children(): void
    {
        $primary = ResultadoScraping::factory()->create(['secundario_de' => null]);
        $secondary = ResultadoScraping::factory()->create(['secundario_de' => $primary->id]);

        $primary->refresh();
        $this->assertTrue(
            $primary->secondaries->contains('id', $secondary->id),
            'primary->secondaries must contain the secondary article'
        );
    }

    /**
     * P3.T7 — primary() returns null when secundario_de is null (article is itself a primary).
     */
    public function test_primary_returns_null_when_secundario_de_is_null(): void
    {
        $primary = ResultadoScraping::factory()->create(['secundario_de' => null]);

        $this->assertNull($primary->primary, 'Article with secundario_de=null must have null primary relation');
    }

    // =========================================================================
    // P3.T7 — Scopes: onlyPrimaries / secondaries
    // =========================================================================

    /**
     * P3.T7 — onlyPrimaries() scope filters to whereNull('secundario_de').
     */
    public function test_only_primaries_scope_matches_where_null_secundario_de(): void
    {
        // Disable Gemini to avoid job dispatch
        config(['services.gemini.enabled' => false]);

        $primary1 = ResultadoScraping::factory()->create(['secundario_de' => null]);
        $primary2 = ResultadoScraping::factory()->create(['secundario_de' => null]);
        ResultadoScraping::factory()->create(['secundario_de' => $primary1->id]);
        ResultadoScraping::factory()->create(['secundario_de' => $primary2->id]);

        $scopeCount = ResultadoScraping::onlyPrimaries()->count();
        $directCount = ResultadoScraping::whereNull('secundario_de')->count();

        $this->assertSame($directCount, $scopeCount, 'onlyPrimaries() must match whereNull(secundario_de) count');
        $this->assertSame(2, $scopeCount, 'Only 2 primaries should be returned');
    }

    /**
     * P3.T7 — onlySecondaries() scope filters to whereNotNull('secundario_de').
     * NOTE: scope is named onlySecondaries (not secondaries) to avoid collision with secondaries() HasMany.
     */
    public function test_only_secondaries_scope_matches_where_not_null_secundario_de(): void
    {
        // Disable Gemini to avoid job dispatch
        config(['services.gemini.enabled' => false]);

        $primary = ResultadoScraping::factory()->create(['secundario_de' => null]);
        ResultadoScraping::factory()->create(['secundario_de' => $primary->id]);
        ResultadoScraping::factory()->create(['secundario_de' => $primary->id]);

        $scopeCount = ResultadoScraping::onlySecondaries()->count();
        $directCount = ResultadoScraping::whereNotNull('secundario_de')->count();

        $this->assertSame($directCount, $scopeCount, 'onlySecondaries() scope must match whereNotNull(secundario_de) count');
        $this->assertSame(2, $scopeCount, 'Only 2 secondary articles should be returned');
    }

    // =========================================================================
    // Cleanup 1 — RED tests: getScoreColorClass() accessor
    // =========================================================================

    /**
     * Cleanup 1 — score >= 70 returns emerald class.
     */
    public function test_get_score_color_class_returns_emerald_when_score_is_70_or_above(): void
    {
        $r = new ResultadoScraping(['relevance_score' => 70]);
        $this->assertSame('text-emerald-600', $r->getScoreColorClass());
    }

    /**
     * Cleanup 1 — score >= 70 (well above boundary) returns emerald class.
     */
    public function test_get_score_color_class_returns_emerald_when_score_is_above_70(): void
    {
        $r = new ResultadoScraping(['relevance_score' => 95]);
        $this->assertSame('text-emerald-600', $r->getScoreColorClass());
    }

    /**
     * Cleanup 1 — score = 40 (amber boundary) returns amber class.
     */
    public function test_get_score_color_class_returns_amber_when_score_is_40(): void
    {
        $r = new ResultadoScraping(['relevance_score' => 40]);
        $this->assertSame('text-amber-500', $r->getScoreColorClass());
    }

    /**
     * Cleanup 1 — score in [40, 69] returns amber class.
     */
    public function test_get_score_color_class_returns_amber_when_score_is_between_40_and_69(): void
    {
        $r = new ResultadoScraping(['relevance_score' => 55]);
        $this->assertSame('text-amber-500', $r->getScoreColorClass());
    }

    /**
     * Cleanup 1 — score = 39 (just below amber threshold) returns gray class.
     */
    public function test_get_score_color_class_returns_gray_when_score_is_39(): void
    {
        $r = new ResultadoScraping(['relevance_score' => 39]);
        $this->assertSame('text-gray-300', $r->getScoreColorClass());
    }

    /**
     * Cleanup 1 — score = 0 returns gray class.
     */
    public function test_get_score_color_class_returns_gray_when_score_is_zero(): void
    {
        $r = new ResultadoScraping(['relevance_score' => 0]);
        $this->assertSame('text-gray-300', $r->getScoreColorClass());
    }

    /**
     * Cleanup 1 — null relevance_score returns gray class (safe default).
     */
    public function test_get_score_color_class_returns_gray_when_score_is_null(): void
    {
        $r = new ResultadoScraping(['relevance_score' => null]);
        $this->assertSame('text-gray-300', $r->getScoreColorClass());
    }
}
