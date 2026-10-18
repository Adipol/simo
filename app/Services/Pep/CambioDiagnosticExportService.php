<?php

declare(strict_types=1);

namespace App\Services\Pep;

use App\Models\Cambio;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Pep\DTOs\CambioDiagnosticSampleDTO;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use JsonException;

final class CambioDiagnosticExportService
{
    public const FILENAME = 'cambios-pep-diagnostic-sample.ndjson';

    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    public const STRATUM_STRUCTURED_REPLACEMENT = 'structured_replacement';

    public const STRATUM_GEMINI_REPLACEMENT = 'gemini_only_replacement';

    public const STRATUM_NAME_PRESENCE = 'name_presence_without_replacement';

    public const STRATUM_TEXT_NOISE = 'heuristic_text_noise';

    private const MAX_RECORDS = 60;

    private const MAX_PER_STRATUM = 15;

    private const MAX_PER_WINDOW = 5;

    private const MAX_PER_SOURCE = 3;

    private const WINDOW_DAYS = 60;

    private const WINDOW_COUNT = 3;

    /** @var array<int,string> */
    private const STRATA = [
        self::STRATUM_STRUCTURED_REPLACEMENT,
        self::STRATUM_GEMINI_REPLACEMENT,
        self::STRATUM_NAME_PRESENCE,
        self::STRATUM_TEXT_NOISE,
    ];

    public function __construct(
        private readonly GeminiPromptBuilder $promptBuilder,
    ) {}

    /**
     * @return Generator<int,string,void,void>
     */
    public function ndjsonLines(?CarbonImmutable $referenceTime = null): Generator
    {
        $referenceTime ??= CarbonImmutable::now();
        $bytesWritten = 0;
        $recordsWritten = 0;
        $stratumCounts = array_fill_keys(self::STRATA, 0);
        $sourceCounts = [];

        for ($window = 0; $window < self::WINDOW_COUNT; $window++) {
            $windowCounts = array_fill_keys(self::STRATA, 0);
            $windowEnd = $referenceTime->subDays($window * self::WINDOW_DAYS);
            $windowStart = $referenceTime->subDays(($window + 1) * self::WINDOW_DAYS);

            foreach ($this->eligibleRecords($windowStart, $windowEnd, $window === 0) as $cambio) {
                $stratum = $this->classify($cambio);

                if ($stratum === null
                    || $windowCounts[$stratum] >= self::MAX_PER_WINDOW
                    || $stratumCounts[$stratum] >= self::MAX_PER_STRATUM
                    || ($sourceCounts[$cambio->fuente_id] ?? 0) >= self::MAX_PER_SOURCE) {
                    continue;
                }

                $line = $this->encode($this->toDto($cambio, $stratum));

                if ($line === null || $bytesWritten + strlen($line) > self::MAX_RESPONSE_BYTES) {
                    continue;
                }

                $bytesWritten += strlen($line);
                $recordsWritten++;
                $windowCounts[$stratum]++;
                $stratumCounts[$stratum]++;
                $sourceCounts[$cambio->fuente_id] = ($sourceCounts[$cambio->fuente_id] ?? 0) + 1;

                yield $line;

                if ($recordsWritten >= self::MAX_RECORDS || $bytesWritten >= self::MAX_RESPONSE_BYTES) {
                    return;
                }

                if ($this->windowIsFull($windowCounts)) {
                    break;
                }
            }
        }
    }

    /** @return LazyCollection<int,Cambio> */
    private function eligibleRecords(CarbonImmutable $start, CarbonImmutable $end, bool $includeEnd): LazyCollection
    {
        return Cambio::query()
            ->select([
                'id',
                'fuente_id',
                'fecha',
                'lineas_quitadas',
                'lineas_nuevas',
                'diff_texto',
                'autoridades_eventos_json',
                'feed_status',
                'posibles_peps',
                'revisado',
                'gemini_analyzed',
                'gemini_analyzed_at',
                'gemini_analisis_json',
                'imagenes_cambio_json',
            ])
            ->with([
                'fuente:id,nombre,organismo,pais,url',
                'authorityRemovalReview:id,cambio_confirmado_id,estado',
            ])
            ->where('gemini_analyzed', true)
            ->whereNotNull('gemini_analyzed_at')
            ->whereNotNull('gemini_analisis_json')
            ->where('fecha', '>=', $start)
            ->when(
                $includeEnd,
                static fn (Builder $query): Builder => $query->where('fecha', '<=', $end),
                static fn (Builder $query): Builder => $query->where('fecha', '<', $end),
            )
            ->where(static function (Builder $query): void {
                $query->where(static function (Builder $diff): void {
                    $diff->whereNotNull('diff_texto')
                        ->where('diff_texto', '!=', '');
                })->orWhereNotNull('autoridades_eventos_json');
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->lazy(100);
    }

    private function classify(Cambio $cambio): ?string
    {
        $authorityEvents = is_array($cambio->autoridades_eventos_json['events'] ?? null)
            ? $cambio->autoridades_eventos_json['events']
            : [];

        if (trim((string) ($cambio->diff_texto ?? '')) === '' && $authorityEvents === []) {
            return null;
        }

        $analysis = is_array($cambio->gemini_analisis_json) ? $cambio->gemini_analisis_json : [];
        $personaRemovida = $this->stringValue($analysis, 'persona_removida');
        $personaNueva = $this->stringValue($analysis, 'persona_nueva');
        $personasDetectadas = $this->sanitizeDetectedPeople($analysis);
        $hasReplacementFields = $personaRemovida !== null && $personaNueva !== null;
        $replacementFieldsEmpty = $personaRemovida === null && $personaNueva === null;

        if ($this->hasStructuredReplacement($cambio->autoridades_eventos_json)) {
            return self::STRATUM_STRUCTURED_REPLACEMENT;
        }

        if ($hasReplacementFields) {
            return self::STRATUM_GEMINI_REPLACEMENT;
        }

        if ($replacementFieldsEmpty && $personasDetectadas !== []) {
            return self::STRATUM_NAME_PRESENCE;
        }

        if ($replacementFieldsEmpty && $personasDetectadas === [] && trim((string) $cambio->posibles_peps) !== '') {
            return self::STRATUM_TEXT_NOISE;
        }

        return null;
    }

    private function toDto(Cambio $cambio, string $stratum): CambioDiagnosticSampleDTO
    {
        $analysis = is_array($cambio->gemini_analisis_json) ? $cambio->gemini_analisis_json : [];
        $diff = (string) ($cambio->diff_texto ?? '');
        $diffExcerpt = $this->promptBuilder->truncarDiff($diff);
        $images = is_array($cambio->imagenes_cambio_json) ? $cambio->imagenes_cambio_json : [];
        $authorityReview = $cambio->authorityRemovalReview;

        return CambioDiagnosticSampleDTO::fromArray([
            'sample_stratum' => $stratum,
            'cambio_id' => (int) $cambio->id,
            'feed_status' => $cambio->feed_status->value,
            'fecha' => $cambio->fecha?->toISOString() ?? '',
            'source' => [
                'id' => (int) $cambio->fuente_id,
                'nombre' => $cambio->fuente?->nombre,
                'organismo' => $cambio->fuente?->organismo,
                'pais' => $cambio->fuente?->pais,
                'url' => $cambio->fuente?->url,
            ],
            'lineas_nuevas' => (int) $cambio->lineas_nuevas,
            'lineas_quitadas' => (int) $cambio->lineas_quitadas,
            'diff_excerpt' => $diffExcerpt,
            'diff_truncated' => $diffExcerpt !== $diff,
            'posibles_peps' => $this->possiblePeps($cambio),
            'autoridades_eventos' => $this->sanitizeAuthorityEvents($cambio->autoridades_eventos_json),
            'persona_removida' => $this->stringValue($analysis, 'persona_removida'),
            'persona_nueva' => $this->stringValue($analysis, 'persona_nueva'),
            'cargo' => $this->stringValue($analysis, 'cargo'),
            'es_mae' => (bool) ($analysis['es_mae'] ?? false),
            'riesgo' => $this->stringValue($analysis, 'riesgo'),
            'analisis' => $this->stringValue($analysis, 'analisis'),
            'personas_detectadas' => $this->sanitizeDetectedPeople($analysis),
            'gemini_analyzed_at' => $cambio->gemini_analyzed_at?->toISOString() ?? '',
            'has_images' => $images !== [],
            'image_count' => count($images),
            'has_authority_review' => $authorityReview !== null,
            'authority_review_status' => $authorityReview?->estado?->value,
            'revisado' => (bool) $cambio->revisado,
        ]);
    }

    private function encode(CambioDiagnosticSampleDTO $dto): ?string
    {
        try {
            return json_encode(
                $dto->toArray(),
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                64,
            )."\n";
        } catch (JsonException) {
            return null;
        }
    }

    /** @param  array<string,int>  $windowCounts */
    private function windowIsFull(array $windowCounts): bool
    {
        foreach (self::STRATA as $stratum) {
            if ($windowCounts[$stratum] < self::MAX_PER_WINDOW) {
                return false;
            }
        }

        return true;
    }

    private function hasStructuredReplacement(?array $payload): bool
    {
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];

        foreach ($events as $event) {
            if (is_array($event) && ($event['type'] ?? null) === 'reemplazo') {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function possiblePeps(Cambio $cambio): array
    {
        $lines = preg_split('/\R/', (string) ($cambio->posibles_peps ?? '')) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @param  array<string,scalar|array|null>  $analysis
     * @return array<int,array{nombre:string,cargo:?string}>
     */
    private function sanitizeDetectedPeople(array $analysis): array
    {
        $people = is_array($analysis['personas_detectadas'] ?? null) ? $analysis['personas_detectadas'] : [];
        $sanitized = [];

        foreach ($people as $person) {
            if (! is_array($person)) {
                continue;
            }

            $name = $this->stringValue($person, 'nombre');

            if ($name === null) {
                continue;
            }

            $sanitized[] = [
                'nombre' => $name,
                'cargo' => $this->stringValue($person, 'cargo'),
            ];
        }

        return $sanitized;
    }

    /**
     * @return array{version:int,events:array<int,array{type:string,old:?array{cargo:?string,persona:?string},new:?array{cargo:?string,persona:?string}}>}
     */
    private function sanitizeAuthorityEvents(?array $payload): array
    {
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        $sanitized = [];

        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                continue;
            }

            $old = is_array($event['old'] ?? null) ? $event['old'] : null;
            $new = is_array($event['new'] ?? null) ? $event['new'] : null;
            $sanitized[] = [
                'type' => $event['type'],
                'old' => $this->sanitizeAuthority($old),
                'new' => $this->sanitizeAuthority($new),
            ];
        }

        return [
            'version' => is_int($payload['version'] ?? null) ? $payload['version'] : 1,
            'events' => $sanitized,
        ];
    }

    /** @return array{cargo:?string,persona:?string}|null */
    private function sanitizeAuthority(?array $authority): ?array
    {
        if ($authority === null) {
            return null;
        }

        return [
            'cargo' => $this->stringValue($authority, 'cargo'),
            'persona' => $this->stringValue($authority, 'persona'),
        ];
    }

    /** @param  array<string,scalar|array|null>  $data */
    private function stringValue(array $data, string $key): ?string
    {
        if (! isset($data[$key]) || ! is_string($data[$key])) {
            return null;
        }

        $value = trim($data[$key]);

        return $value === '' ? null : $value;
    }
}
