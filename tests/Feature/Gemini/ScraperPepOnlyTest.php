<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Models\GeminiUsageLog;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScraperPepOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.min_confianza_pep' => 70]);
        Http::preventStrayRequests();
    }

    public function test_opi_only_response_does_not_persist_people_but_counts_usage(): void
    {
        $record = $this->analyze([$this->person('Rodrigo Vargas', 'OPI', 99)]);

        $this->assertSame([], $record->personas()->pluck('nombre')->all());
        $this->assertTrue($record->gemini_analyzed);
        $this->assertNotNull($record->gemini_analyzed_at);
        $this->assertSame('Respuesta de prueba', $record->gemini_motivo);
        $this->assertDatabaseHas('gemini_usage_log', [
            'resultado_scraping_id' => $record->id,
            'request_type' => 'filtro',
            'prompt_tokens' => 80,
            'completion_tokens' => 40,
            'total_tokens' => 120,
        ]);
        Http::assertSentCount(1);
    }

    public function test_opi_only_response_cannot_activate_pep_flag(): void
    {
        $record = $this->analyze([$this->person('Rodrigo Vargas', 'OPI', 99)]);

        $this->assertFalse($record->gemini_is_pep);
    }

    public function test_mixed_response_persists_only_pep_and_keeps_positive_flag(): void
    {
        $record = $this->analyze([
            $this->person('Rodrigo Vargas', 'OPI', 99),
            $this->person('Juan Pérez', 'PEP', 90),
        ]);

        $this->assertSame(['Juan Pérez'], $record->personas()->pluck('nombre')->all());
        $persona = $record->personas()->sole();
        $this->assertSame('PEP', $persona->categoria);
        $this->assertSame(90, $persona->confianza);
        $this->assertTrue($persona->threshold_passed);
        $this->assertNotEmpty($persona->nombre_normalizado);
        $this->assertTrue($record->gemini_is_pep);
    }

    public function test_high_confidence_opi_cannot_override_low_confidence_pep(): void
    {
        $record = $this->analyze([
            $this->person('Rodrigo Vargas', 'OPI', 99),
            $this->person('Juan Pérez', 'PEP', 55),
        ]);

        $this->assertFalse($record->gemini_is_pep);
        $this->assertSame(['Juan Pérez'], $record->personas()->pluck('nombre')->all());
        $this->assertFalse($record->personas()->sole()->threshold_passed);
    }

    public static function unchangedPepCases(): array
    {
        return [
            'low confidence retained' => [55, 70, false, null],
            'threshold boundary' => [70, 70, true, 'renuncia'],
            'disabled threshold' => [30, 0, true, 'crimen'],
            'empty response' => [null, 70, false, null],
        ];
    }

    #[DataProvider('unchangedPepCases')]
    public function test_existing_pep_threshold_and_empty_behavior_is_preserved(
        ?int $confidence,
        int $threshold,
        bool $expectedFlag,
        ?string $event,
    ): void {
        config(['services.gemini.min_confianza_pep' => $threshold]);
        $people = $confidence === null ? [] : [
            array_replace($this->person('Juan Pérez', 'PEP', $confidence), ['evento' => $event]),
        ];

        $record = $this->analyze($people);

        $this->assertSame($expectedFlag, $record->gemini_is_pep);
        $this->assertSame(count($people), $record->personas()->count());
        $this->assertTrue($record->gemini_analyzed);
        $this->assertNotNull($record->gemini_analyzed_at);
        if ($confidence !== null) {
            $persona = $record->personas()->sole();
            $this->assertSame($expectedFlag, $persona->threshold_passed);
            $this->assertSame($confidence, $persona->confianza);
            $this->assertSame($event, $persona->evento);
        }
    }

    public function test_already_analyzed_opi_history_is_not_reclassified_or_requested_again(): void
    {
        $record = $this->record();
        $record->update([
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => now()->subDay(),
            'gemini_is_pep' => true,
            'gemini_motivo' => 'Resultado histórico',
            'gemini_confianza' => 99,
        ]);
        $person = ResultadoPersona::create(array_merge($this->person('Rodrigo Vargas', 'OPI', 99), [
            'resultado_scraping_id' => $record->id,
            'threshold_passed' => true,
        ]));
        $originalRecord = $record->refresh()->getRawOriginal();
        $originalPerson = $person->refresh()->getRawOriginal();
        Http::fake();

        $this->service()->analizarLote(collect([$record]));

        $this->assertSame($originalRecord, $record->fresh()->getRawOriginal());
        $this->assertSame($originalPerson, $person->fresh()->getRawOriginal());
        $this->assertSame(1, $record->personas()->count());
        $this->assertSame(0, GeminiUsageLog::query()->count());
        Http::assertNothingSent();
    }

    private function analyze(array $people): ResultadoScraping
    {
        $record = $this->record();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'personas' => $people,
                'motivo_general' => 'Respuesta de prueba',
            ], JSON_THROW_ON_ERROR)]]]]],
            'usageMetadata' => [
                'promptTokenCount' => 80,
                'candidatesTokenCount' => 40,
                'totalTokenCount' => 120,
            ],
        ])]);

        $this->service()->analizarLote(collect([$record]));

        return $record->refresh();
    }

    private function record(): ResultadoScraping
    {
        return ResultadoScraping::withoutEvents(fn (): ResultadoScraping => ResultadoScraping::create([
            'url' => 'https://example.com/pep-only',
            'keyword' => 'ministro',
            'pais' => 'BO',
            'categoria' => 'OPI-crimen',
            'titulo' => 'Designan ministro mientras fiscal investiga crimen',
            'contexto' => 'Designan al ministro Juan Pérez. El fiscal investiga a Rodrigo Vargas.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ]));
    }

    private function person(string $name, string $category, int $confidence): array
    {
        return [
            'nombre' => $name,
            'cargo' => $category === 'PEP' ? 'Ministro' : null,
            'categoria' => $category,
            'entidad_tipo' => $category === 'PEP' ? 'publica' : 'desconocido',
            'confianza' => $confidence,
            'evento' => $category === 'PEP' ? 'designacion' : 'crimen',
            'motivo' => 'Clasificación de prueba',
        ];
    }

    private function service(): GeminiFiltroService
    {
        return new GeminiFiltroService(
            new GeminiService(apiKey: 'test-key'),
            new GeminiPromptBuilder,
        );
    }
}
