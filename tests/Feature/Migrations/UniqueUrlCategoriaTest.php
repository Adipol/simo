<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for Migration M3b — UNIQUE (url, categoria) constraint on resultados_scraping.
 *
 * Spec capability: dedup-by-url-categoria
 *
 * These tests verify that the UNIQUE constraint:
 *   1. Allows the same URL with DIFFERENT categoria (two rows allowed).
 *   2. Rejects the same (url, categoria) pair (QueryException on duplicate).
 *   3. Allows multiple NULL-categoria rows (Postgres/SQLite treat NULLs as distinct).
 *
 * These tests depend on M3b migration being applied. Since RefreshDatabase runs
 * ALL migrations, they will be GREEN once M3b exists.
 *
 * Written as TDD RED before M3b is created (constraint does not exist yet),
 * then verified GREEN after M3b is applied.
 */
class UniqueUrlCategoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.enabled' => false]);
    }

    /**
     * Helper: insert a minimal row directly via DB::table.
     */
    private function insertRow(string $url, ?string $categoria): void
    {
        DB::table('resultados_scraping')->insert([
            'url'             => $url,
            'keyword'         => 'test',
            'pais'            => 'BO',
            'categoria'       => $categoria,
            'relevance_score' => 50,
            'found_in_title'  => 0,
            'leido'           => 0,
            'descartado'      => 0,
            'gemini_analyzed' => 0,
            'fecha_encontrado' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Spec scenario: Same URL, different categoria → two rows allowed.
     *
     * GIVEN URL 'https://example.com/doc' exists with categoria='PEP'
     * WHEN the scraper inserts the same URL with categoria='OPI'
     * THEN a second row is created; total rows for that URL = 2
     */
    public function test_constraint_allows_same_url_different_categoria(): void
    {
        $url = 'https://example.com/constraint-test-diff-cat';

        $this->insertRow($url, 'PEP');
        $this->insertRow($url, 'OPI');

        $count = DB::table('resultados_scraping')->where('url', $url)->count();
        $this->assertSame(2, (int) $count, 'Same URL with different categoria must result in 2 rows');
    }

    /**
     * Spec scenario: Same URL, same categoria → second INSERT must be rejected.
     *
     * GIVEN URL 'https://example.com/doc' with categoria='PEP' already exists
     * WHEN the same (url, categoria) is inserted again
     * THEN a QueryException with a UNIQUE constraint violation is thrown
     */
    public function test_constraint_rejects_same_url_same_categoria(): void
    {
        $url = 'https://example.com/constraint-test-same-cat';

        $this->insertRow($url, 'PEP');

        $this->expectException(QueryException::class);

        $this->insertRow($url, 'PEP');
    }

    /**
     * Spec scenario: Multiple NULL-categoria rows are allowed (legacy rows).
     *
     * Both Postgres and SQLite treat NULLs as distinct values in UNIQUE indexes,
     * so two rows with the same URL and NULL categoria are both permitted.
     * This is the intended D1 design for legacy rows without a categoria.
     */
    public function test_constraint_allows_multiple_null_categoria_legacy_rows(): void
    {
        $url = 'https://example.com/constraint-test-null-cat';

        $this->insertRow($url, null);
        $this->insertRow($url, null); // Must NOT throw

        $count = DB::table('resultados_scraping')->where('url', $url)->whereNull('categoria')->count();
        $this->assertSame(2, (int) $count, 'Two rows with NULL categoria on the same URL must both be allowed');
    }
}
