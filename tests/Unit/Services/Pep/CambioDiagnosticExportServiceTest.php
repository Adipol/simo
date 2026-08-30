<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pep;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Pep\CambioDiagnosticExportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CambioDiagnosticExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-30 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(self::NOW);
        config(['services.gemini.enabled' => false]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_hard_eligible_records_from_the_last_180_days_are_exported(): void
    {
        $eligible = $this->createCambio();
        $boundary = $this->createCambio(['fecha' => $this->reference()->subDays(180)]);
        $structuredOnly = $this->createCambio([
            'diff_texto' => '',
            'autoridades_eventos_json' => $this->replacementEvents(),
        ]);

        $this->createCambio(['fecha' => $this->reference()->subDays(180)->subSecond()]);
        $this->createCambio(['fecha' => $this->reference()->addSecond()]);
        $this->createCambio(['gemini_analyzed' => false]);
        $this->createCambio(['gemini_analyzed_at' => null]);
        $this->createCambio(['gemini_analisis_json' => null]);
        $this->createCambio(['diff_texto' => ' ', 'autoridades_eventos_json' => null]);
        $this->createCambio(['diff_texto' => '', 'autoridades_eventos_json' => ['version' => 1, 'events' => []]]);

        $ids = array_column($this->records(), 'cambio_id');

        $this->assertEqualsCanonicalizing([$eligible->id, $boundary->id, $structuredOnly->id], $ids);
    }

    public function test_strata_are_mutually_exclusive_with_structured_evidence_taking_precedence(): void
    {
        $structured = $this->createCambio([
            'autoridades_eventos_json' => $this->replacementEvents(),
            'gemini_analisis_json' => $this->replacementAnalysis(),
        ]);
        $geminiOnly = $this->createCambio();
        $namePresence = $this->createCambio([
            'gemini_analisis_json' => $this->emptyReplacementAnalysis([
                ['nombre' => 'Visible Name', 'cargo' => 'Director'],
            ]),
            'posibles_peps' => 'Visible Name',
        ]);
        $textNoise = $this->createCambio([
            'gemini_analisis_json' => $this->emptyReplacementAnalysis(),
            'posibles_peps' => 'Heuristic Candidate',
        ]);
        $this->createCambio([
            'gemini_analisis_json' => array_replace($this->emptyReplacementAnalysis(), [
                'persona_nueva' => 'Only One Side',
            ]),
            'posibles_peps' => 'Only One Side',
        ]);

        $strataById = [];
        foreach ($this->records() as $record) {
            $strataById[$record['cambio_id']] = $record['sample_stratum'];
        }

        $this->assertSame(CambioDiagnosticExportService::STRATUM_STRUCTURED_REPLACEMENT, $strataById[$structured->id]);
        $this->assertSame(CambioDiagnosticExportService::STRATUM_GEMINI_REPLACEMENT, $strataById[$geminiOnly->id]);
        $this->assertSame(CambioDiagnosticExportService::STRATUM_NAME_PRESENCE, $strataById[$namePresence->id]);
        $this->assertSame(CambioDiagnosticExportService::STRATUM_TEXT_NOISE, $strataById[$textNoise->id]);
        $this->assertCount(4, $strataById);
    }

    public function test_each_stratum_is_limited_to_five_per_window_and_sixty_records_total(): void
    {
        $selectedCandidates = [];
        $excludedCandidates = [];

        foreach (range(0, 2) as $window) {
            foreach ($this->stratumAttributes() as $attributes) {
                foreach (range(1, 6) as $position) {
                    $cambio = $this->createCambio(array_replace($attributes, [
                        'fecha' => $this->reference()->subDays(($window * 60) + $position),
                    ]));

                    if ($position <= 5) {
                        $selectedCandidates[] = $cambio->id;
                    } else {
                        $excludedCandidates[] = $cambio->id;
                    }
                }
            }
        }

        $records = $this->records();
        $ids = array_column($records, 'cambio_id');

        $this->assertCount(60, $records);
        $this->assertEqualsCanonicalizing($selectedCandidates, $ids);
        $this->assertSame([], array_values(array_intersect($excludedCandidates, $ids)));

        foreach (array_keys($this->stratumAttributes()) as $stratum) {
            $this->assertCount(15, array_filter(
                $records,
                static fn (array $record): bool => $record['sample_stratum'] === $stratum,
            ));
        }
    }

    public function test_complete_export_is_limited_to_three_records_per_source(): void
    {
        $source = Fuente::factory()->create();

        foreach (range(1, 8) as $position) {
            $this->createCambio(
                ['fecha' => $this->reference()->subDays($position)],
                $source,
            );
        }

        $records = $this->records();

        $this->assertCount(3, $records);
        $this->assertSame([$source->id], array_values(array_unique(array_column(array_column($records, 'source'), 'id'))));
    }

    public function test_response_body_never_exceeds_two_mebibytes(): void
    {
        foreach (range(1, 3) as $position) {
            $this->createCambio([
                'fecha' => $this->reference()->subDays($position),
                'gemini_analisis_json' => array_replace($this->replacementAnalysis(), [
                    'analisis' => str_repeat('x', 1_100_000),
                ]),
            ]);
        }

        $lines = iterator_to_array(app(CambioDiagnosticExportService::class)->ndjsonLines(), false);
        $body = implode('', $lines);

        $this->assertLessThanOrEqual(CambioDiagnosticExportService::MAX_RESPONSE_BYTES, strlen($body));
        $this->assertCount(1, $lines);
        $this->assertIsArray(json_decode(rtrim($lines[0], "\n"), true, 64, JSON_THROW_ON_ERROR));
    }

    public function test_diff_excerpt_reuses_the_gemini_bounding_behavior(): void
    {
        $diff = implode("\n", array_map(
            static fn (int $line): string => "+Changed authority line {$line} ".str_repeat('x', 40),
            range(1, 400),
        ));
        $cambio = $this->createCambio(['diff_texto' => $diff]);

        $record = collect($this->records())->firstWhere('cambio_id', $cambio->id);

        $this->assertSame(app(GeminiPromptBuilder::class)->truncarDiff($diff), $record['diff_excerpt']);
        $this->assertTrue($record['diff_truncated']);
    }

    /** @return array<int,array<string,bool|int|string|array|null>> */
    private function records(): array
    {
        $records = [];

        foreach (app(CambioDiagnosticExportService::class)->ndjsonLines() as $line) {
            $records[] = json_decode(rtrim($line, "\n"), true, 64, JSON_THROW_ON_ERROR);
        }

        return $records;
    }

    /** @param  array<string,bool|int|string|array|null|CarbonImmutable>  $overrides */
    private function createCambio(array $overrides = [], ?Fuente $source = null): Cambio
    {
        $source ??= Fuente::factory()->create();
        $attributes = array_replace([
            'fuente_id' => $source->id,
            'fecha' => $this->reference()->subDay(),
            'diff_texto' => "+New Person\n-Old Person",
            'autoridades_eventos_json' => null,
            'posibles_peps' => null,
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => $this->reference()->subHour(),
            'gemini_analisis_json' => $this->replacementAnalysis(),
            'imagenes_cambio_json' => null,
        ], $overrides);

        return Cambio::withoutEvents(
            static fn (): Cambio => Cambio::factory()->create($attributes),
        );
    }

    /** @return array<string,bool|string|array|null> */
    private function replacementAnalysis(): array
    {
        return [
            'persona_removida' => 'Old Person',
            'persona_nueva' => 'New Person',
            'cargo' => 'Director',
            'es_mae' => false,
            'riesgo' => 'medio',
            'analisis' => 'Replacement detected.',
            'personas_detectadas' => [],
        ];
    }

    /**
     * @param  array<int,array{nombre:string,cargo:?string}>  $people
     * @return array<string,bool|string|array|null>
     */
    private function emptyReplacementAnalysis(array $people = []): array
    {
        return array_replace($this->replacementAnalysis(), [
            'persona_removida' => null,
            'persona_nueva' => null,
            'personas_detectadas' => $people,
        ]);
    }

    /** @return array{version:int,events:array<int,array<string,array|string>>} */
    private function replacementEvents(): array
    {
        return [
            'version' => 1,
            'events' => [[
                'type' => 'reemplazo',
                'old' => ['cargo' => 'Director', 'persona' => 'Old Person'],
                'new' => ['cargo' => 'Director', 'persona' => 'New Person'],
            ]],
        ];
    }

    /** @return array<string,array<string,string|array|null>> */
    private function stratumAttributes(): array
    {
        return [
            CambioDiagnosticExportService::STRATUM_STRUCTURED_REPLACEMENT => [
                'autoridades_eventos_json' => $this->replacementEvents(),
            ],
            CambioDiagnosticExportService::STRATUM_GEMINI_REPLACEMENT => [],
            CambioDiagnosticExportService::STRATUM_NAME_PRESENCE => [
                'gemini_analisis_json' => $this->emptyReplacementAnalysis([
                    ['nombre' => 'Visible Name', 'cargo' => null],
                ]),
            ],
            CambioDiagnosticExportService::STRATUM_TEXT_NOISE => [
                'gemini_analisis_json' => $this->emptyReplacementAnalysis(),
                'posibles_peps' => 'Heuristic Candidate',
            ],
        ];
    }

    private function reference(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }
}
