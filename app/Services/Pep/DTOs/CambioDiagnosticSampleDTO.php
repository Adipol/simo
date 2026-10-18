<?php

declare(strict_types=1);

namespace App\Services\Pep\DTOs;

final readonly class CambioDiagnosticSampleDTO
{
    /**
     * @param  array{id:int,nombre:?string,organismo:?string,pais:?string,url:?string}  $source
     * @param  array<int,string>  $posiblesPeps
     * @param  array{version:int,events:array<int,array{type:string,old:?array{cargo:?string,persona:?string},new:?array{cargo:?string,persona:?string}}>}  $autoridadesEventos
     * @param  array<int,array{nombre:string,cargo:?string}>  $personasDetectadas
     */
    public function __construct(
        public string $sampleStratum,
        public int $cambioId,
        public string $feedStatus,
        public string $fecha,
        public array $source,
        public int $lineasNuevas,
        public int $lineasQuitadas,
        public string $diffExcerpt,
        public bool $diffTruncated,
        public array $posiblesPeps,
        public array $autoridadesEventos,
        public ?string $personaRemovida,
        public ?string $personaNueva,
        public ?string $cargo,
        public bool $esMae,
        public ?string $riesgo,
        public ?string $analisis,
        public array $personasDetectadas,
        public string $geminiAnalyzedAt,
        public bool $hasImages,
        public int $imageCount,
        public bool $hasAuthorityReview,
        public ?string $authorityReviewStatus,
        public bool $revisado,
    ) {}

    /**
     * @param  array{
     *     sample_stratum:string,
     *     cambio_id:int,
     *     feed_status:string,
     *     fecha:string,
     *     source:array{id:int,nombre:?string,organismo:?string,pais:?string,url:?string},
     *     lineas_nuevas:int,
     *     lineas_quitadas:int,
     *     diff_excerpt:string,
     *     diff_truncated:bool,
     *     posibles_peps:array<int,string>,
     *     autoridades_eventos:array{version:int,events:array<int,array{type:string,old:?array{cargo:?string,persona:?string},new:?array{cargo:?string,persona:?string}}>} ,
     *     persona_removida:?string,
     *     persona_nueva:?string,
     *     cargo:?string,
     *     es_mae:bool,
     *     riesgo:?string,
     *     analisis:?string,
     *     personas_detectadas:array<int,array{nombre:string,cargo:?string}>,
     *     gemini_analyzed_at:string,
     *     has_images:bool,
     *     image_count:int,
     *     has_authority_review:bool,
     *     authority_review_status:?string,
     *     revisado:bool
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sampleStratum: $data['sample_stratum'],
            cambioId: $data['cambio_id'],
            feedStatus: $data['feed_status'],
            fecha: $data['fecha'],
            source: $data['source'],
            lineasNuevas: $data['lineas_nuevas'],
            lineasQuitadas: $data['lineas_quitadas'],
            diffExcerpt: $data['diff_excerpt'],
            diffTruncated: $data['diff_truncated'],
            posiblesPeps: $data['posibles_peps'],
            autoridadesEventos: $data['autoridades_eventos'],
            personaRemovida: $data['persona_removida'],
            personaNueva: $data['persona_nueva'],
            cargo: $data['cargo'],
            esMae: $data['es_mae'],
            riesgo: $data['riesgo'],
            analisis: $data['analisis'],
            personasDetectadas: $data['personas_detectadas'],
            geminiAnalyzedAt: $data['gemini_analyzed_at'],
            hasImages: $data['has_images'],
            imageCount: $data['image_count'],
            hasAuthorityReview: $data['has_authority_review'],
            authorityReviewStatus: $data['authority_review_status'],
            revisado: $data['revisado'],
        );
    }

    /** @return array<string, bool|int|string|array|null> */
    public function toArray(): array
    {
        return [
            'sample_stratum' => $this->sampleStratum,
            'cambio_id' => $this->cambioId,
            'feed_status' => $this->feedStatus,
            'fecha' => $this->fecha,
            'source' => $this->source,
            'lineas_nuevas' => $this->lineasNuevas,
            'lineas_quitadas' => $this->lineasQuitadas,
            'diff_excerpt' => $this->diffExcerpt,
            'diff_truncated' => $this->diffTruncated,
            'posibles_peps' => $this->posiblesPeps,
            'autoridades_eventos' => $this->autoridadesEventos,
            'persona_removida' => $this->personaRemovida,
            'persona_nueva' => $this->personaNueva,
            'cargo' => $this->cargo,
            'es_mae' => $this->esMae,
            'riesgo' => $this->riesgo,
            'analisis' => $this->analisis,
            'personas_detectadas' => $this->personasDetectadas,
            'gemini_analyzed_at' => $this->geminiAnalyzedAt,
            'has_images' => $this->hasImages,
            'image_count' => $this->imageCount,
            'authority_review' => [
                'present' => $this->hasAuthorityReview,
                'status' => $this->authorityReviewStatus,
            ],
            'revisado' => $this->revisado,
        ];
    }
}
