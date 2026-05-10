<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Export;

use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Services\Export\ResultadosCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P4.T14 — RED tests for refactored ResultadosCsvExporter.
 *
 * New behavior (per user decision DECISION 5):
 *  - 1 row per persona (1 article with 2 personas → 2 CSV rows)
 *  - 1 article with 0 personas → 1 row with empty persona fields
 *  - Columns reference resultado_personas, NOT legacy gemini_* columns
 */
class ResultadosCsvExporterTest extends TestCase
{
    use RefreshDatabase;

    private ResultadosCsvExporter $exporter;
    private SitioWeb $sitio;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);

        $this->exporter = new ResultadosCsvExporter();

        $this->sitio = SitioWeb::create([
            'url'    => 'https://example.com',
            'nombre' => 'Test Sitio',
            'pais'   => 'BO',
            'activo' => true,
        ]);
    }

    private function makeArticle(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url'              => 'https://example.com/article-'.uniqid(),
            'keyword'          => 'test keyword',
            'sitio_id'         => $this->sitio->id,
            'pais'             => 'BO',
            'titulo'           => 'Test article title',
            'contexto'         => 'Some context text',
            'fecha_encontrado' => now(),
            'relevance_score'  => 50,
            'leido'            => false,
            'descartado'       => false,
            'gemini_analyzed'  => true,
            'gemini_is_pep'    => false,
            'gemini_motivo'    => 'Test motivo',
        ], $overrides));
    }

    /**
     * Helper to capture CSV output as an array of rows (without header).
     * Returns array of parsed CSV lines.
     *
     * @return array<array<string>>
     */
    private function getCsvRows(ResultadoScraping $article): array
    {
        $query = ResultadoScraping::where('id', $article->id);

        $rows = [];
        $isHeader = true;

        ob_start();
        $response = $this->exporter->stream($query, 'test.csv');
        // Execute the streamed response callback
        $response->send();
        $output = ob_get_clean();

        // Remove BOM if present
        $output = ltrim($output, "\xEF\xBB\xBF");

        $lines = array_filter(explode("\n", trim($output)));
        foreach ($lines as $line) {
            if ($isHeader) {
                $isHeader = false;
                continue; // skip header
            }
            $rows[] = str_getcsv($line);
        }

        return $rows;
    }

    // ─── 1 article + 0 personas → 1 row ──────────────────────────────────────

    public function test_article_with_zero_personas_generates_one_row_with_empty_fields(): void
    {
        $article = $this->makeArticle();
        // No personas added

        $query = ResultadoScraping::where('id', $article->id)->with('personas');

        // Capture the CSV content
        ob_start();
        $response = $this->exporter->stream($query, 'test.csv');
        $response->send();
        $output = ob_get_clean();

        // Remove BOM and parse
        $output = ltrim($output, "\xEF\xBB\xBF");
        $lines = array_values(array_filter(explode("\n", trim($output))));

        // 1 header + 1 data row
        $this->assertCount(2, $lines, 'Article with 0 personas must generate exactly 1 data row + 1 header');

        $dataRow = str_getcsv($lines[1]);
        // persona_nombre column should be empty
        $this->assertSame('', $dataRow[11] ?? '', 'persona_nombre must be empty when no personas');
    }

    // ─── 1 article + 1 persona → 1 row ───────────────────────────────────────

    public function test_article_with_one_persona_generates_one_data_row(): void
    {
        $article = $this->makeArticle();
        ResultadoPersona::create([
            'resultado_scraping_id' => $article->id,
            'nombre'                => 'Carlos Cronenbold',
            'cargo'                 => 'Gerente YPFB',
            'categoria'             => 'PEP',
            'confianza'             => 92,
            'threshold_passed'      => true,
        ]);

        $query = ResultadoScraping::where('id', $article->id)->with('personas');

        ob_start();
        $response = $this->exporter->stream($query, 'test.csv');
        $response->send();
        $output = ob_get_clean();

        $output = ltrim($output, "\xEF\xBB\xBF");
        $lines = array_values(array_filter(explode("\n", trim($output))));

        $this->assertCount(2, $lines, '1 article + 1 persona must generate 1 header + 1 data row');

        $dataRow = str_getcsv($lines[1]);
        $this->assertSame('Carlos Cronenbold', $dataRow[11] ?? '', 'persona_nombre must come from resultado_personas');
        $this->assertSame('Gerente YPFB', $dataRow[12] ?? '', 'persona_cargo must come from resultado_personas');
        $this->assertSame('PEP', $dataRow[13] ?? '', 'persona_categoria must come from resultado_personas');
    }

    // ─── 1 article + 2 personas → 2 rows ─────────────────────────────────────

    public function test_article_with_two_personas_generates_two_data_rows(): void
    {
        $article = $this->makeArticle();

        ResultadoPersona::create([
            'resultado_scraping_id' => $article->id,
            'nombre'                => 'Persona Uno',
            'cargo'                 => 'Ministro',
            'categoria'             => 'PEP',
            'confianza'             => 90,
            'threshold_passed'      => true,
        ]);
        ResultadoPersona::create([
            'resultado_scraping_id' => $article->id,
            'nombre'                => 'Persona Dos',
            'cargo'                 => 'Director',
            'categoria'             => 'OPI',
            'confianza'             => 75,
            'threshold_passed'      => false,
        ]);

        $query = ResultadoScraping::where('id', $article->id)->with('personas');

        ob_start();
        $response = $this->exporter->stream($query, 'test.csv');
        $response->send();
        $output = ob_get_clean();

        $output = ltrim($output, "\xEF\xBB\xBF");
        $lines = array_values(array_filter(explode("\n", trim($output))));

        $this->assertCount(3, $lines, '1 article + 2 personas must generate 1 header + 2 data rows');

        $names = [
            str_getcsv($lines[1])[11] ?? '',
            str_getcsv($lines[2])[11] ?? '',
        ];
        $this->assertContains('Persona Uno', $names, 'First persona must appear in CSV');
        $this->assertContains('Persona Dos', $names, 'Second persona must appear in CSV');
    }

    // ─── Header columns don't reference legacy gemini_* names ────────────────

    public function test_csv_header_does_not_reference_legacy_gemini_columns(): void
    {
        $article = $this->makeArticle();
        $query = ResultadoScraping::where('id', $article->id)->with('personas');

        ob_start();
        $response = $this->exporter->stream($query, 'test.csv');
        $response->send();
        $output = ob_get_clean();

        $output = ltrim($output, "\xEF\xBB\xBF");
        $lines = array_values(array_filter(explode("\n", trim($output))));
        $header = strtolower($lines[0]);

        $this->assertStringNotContainsString('gemini_nombre', $header, 'Header must NOT contain gemini_nombre');
        $this->assertStringNotContainsString('gemini_cargo', $header, 'Header must NOT contain gemini_cargo');
        $this->assertStringNotContainsString('gemini_categoria', $header, 'Header must NOT contain gemini_categoria');
        $this->assertStringNotContainsString('gemini_confianza', $header, 'Header must NOT contain gemini_confianza');
    }
}
