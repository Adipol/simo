<?php
declare(strict_types=1);
namespace Tests\Feature\Gemini;

use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThresholdConfianzaTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/test',
            'keyword' => 'test',
            'pais' => 'BO',
            'categoria' => 'PEP-designacion',
            'titulo' => 'Test',
            'contexto' => 'El ministro Juan Perez firmo decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    private function fakeResponse(array $data): string
    {
        return json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]]]);
    }

    private function makeService(): GeminiFiltroService
    {
        return new GeminiFiltroService(
            new GeminiService(apiKey: 'test-key'),
            new GeminiPromptBuilder,
        );
    }

    public function test_alta_confianza_pasa_threshold(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.min_confianza_pep' => 70]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'is_pep' => true, 'nombre' => 'Juan Perez', 'cargo' => 'Ministro',
            'categoria' => 'PEP', 'entidad_tipo' => 'publica', 'confianza' => 95, 'motivo' => 'Ministro confirmado',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertTrue($record->gemini_is_pep);
        $this->assertSame(95, $record->gemini_confianza);
        $this->assertStringNotContainsString('THRESHOLD', $record->gemini_motivo);
    }

    public function test_baja_confianza_bloqueada_por_threshold(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.min_confianza_pep' => 70]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'is_pep' => true, 'nombre' => 'Carlos Hurtado', 'cargo' => 'Desconocido',
            'categoria' => 'PEP', 'entidad_tipo' => 'desconocido', 'confianza' => 55, 'motivo' => 'Podria ser PEP',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertFalse($record->gemini_is_pep);
        $this->assertSame(55, $record->gemini_confianza);
        $this->assertStringContainsString('THRESHOLD', $record->gemini_motivo);
    }

    public function test_threshold_cero_desactiva_filtrado(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.min_confianza_pep' => 0]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'is_pep' => true, 'nombre' => 'Alguien', 'cargo' => 'Algo',
            'categoria' => 'PEP', 'entidad_tipo' => 'publica', 'confianza' => 30, 'motivo' => 'Baja confianza',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertTrue($record->gemini_is_pep);
        $this->assertSame(30, $record->gemini_confianza);
    }

    public function test_threshold_default_es_70_sin_env(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        config(['services.gemini.min_confianza_pep' => 70]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'is_pep' => true, 'nombre' => 'Test', 'cargo' => 'Test',
            'categoria' => 'PEP', 'entidad_tipo' => 'publica', 'confianza' => 65, 'motivo' => 'Dudoso',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertFalse($record->gemini_is_pep);
        $this->assertStringContainsString('THRESHOLD', $record->gemini_motivo);
    }

    public function test_no_pep_no_afectado_por_threshold(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.min_confianza_pep' => 70]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'is_pep' => false, 'nombre' => null, 'cargo' => null,
            'categoria' => null, 'entidad_tipo' => null, 'confianza' => 30, 'motivo' => 'No relevante',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertFalse($record->gemini_is_pep);
        $this->assertStringNotContainsString('THRESHOLD', $record->gemini_motivo);
    }
}
