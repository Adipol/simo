<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Models\ResultadoPersona;
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
            'personas' => [[
                'nombre' => 'Juan Perez',
                'cargo' => 'Ministro',
                'categoria' => 'PEP',
                'entidad_tipo' => 'publica',
                'confianza' => 95,
                'evento' => 'designacion',
                'motivo' => 'Ministro confirmado',
            ]],
            'motivo_general' => 'Designación ministerial confirmada',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertTrue($record->gemini_is_pep);

        $persona = ResultadoPersona::where('resultado_scraping_id', $record->id)->first();
        $this->assertNotNull($persona);
        $this->assertSame(95, $persona->confianza);
        $this->assertTrue($persona->threshold_passed);
    }

    public function test_baja_confianza_bloqueada_por_threshold(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.min_confianza_pep' => 70]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'personas' => [[
                'nombre' => 'Carlos Hurtado',
                'cargo' => 'Desconocido',
                'categoria' => 'PEP',
                'entidad_tipo' => 'desconocido',
                'confianza' => 55,
                'evento' => null,
                'motivo' => 'Podria ser PEP',
            ]],
            'motivo_general' => 'Posible PEP con baja confianza',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertFalse($record->gemini_is_pep);

        $persona = ResultadoPersona::where('resultado_scraping_id', $record->id)->first();
        $this->assertNotNull($persona);
        $this->assertSame(55, $persona->confianza);
        $this->assertFalse($persona->threshold_passed);
    }

    public function test_threshold_cero_desactiva_filtrado(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.min_confianza_pep' => 0]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'personas' => [[
                'nombre' => 'Alguien',
                'cargo' => 'Algo',
                'categoria' => 'PEP',
                'entidad_tipo' => 'publica',
                'confianza' => 30,
                'evento' => null,
                'motivo' => 'Baja confianza',
            ]],
            'motivo_general' => 'Artículo con baja confianza',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertTrue($record->gemini_is_pep);

        $persona = ResultadoPersona::where('resultado_scraping_id', $record->id)->first();
        $this->assertNotNull($persona);
        $this->assertSame(30, $persona->confianza);
        $this->assertTrue($persona->threshold_passed);
    }

    public function test_threshold_default_es_70_sin_env(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        config(['services.gemini.min_confianza_pep' => 70]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'personas' => [[
                'nombre' => 'Test',
                'cargo' => 'Test',
                'categoria' => 'PEP',
                'entidad_tipo' => 'publica',
                'confianza' => 65,
                'evento' => null,
                'motivo' => 'Dudoso',
            ]],
            'motivo_general' => 'Persona de bajo confianza',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertFalse($record->gemini_is_pep);

        $persona = ResultadoPersona::where('resultado_scraping_id', $record->id)->first();
        $this->assertNotNull($persona);
        $this->assertFalse($persona->threshold_passed);
    }

    public function test_no_pep_no_afectado_por_threshold(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.min_confianza_pep' => 70]);
        $record = $this->createRecord();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->fakeResponse([
            'personas' => [],
            'motivo_general' => 'No relevante',
        ]))]);

        $this->makeService()->analizarLote(collect([$record]));
        $record->refresh();

        $this->assertFalse($record->gemini_is_pep);
        $this->assertSame(0, ResultadoPersona::where('resultado_scraping_id', $record->id)->count());
    }
}
