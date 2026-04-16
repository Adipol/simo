<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\FiltroResultadoDTO;
use App\Services\Normalization\NombreNormalizador;
use App\Services\Normalization\NombreNormalizadorInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GeminiFiltroService
{
    public function __construct(
        private GeminiService $gemini,
        private GeminiPromptBuilder $builder,
        private NombreNormalizadorInterface $normalizador = new NombreNormalizador,
    ) {}

    public function analizarLote(Collection $records): void
    {
        foreach ($records as $record) {
            $this->procesarRegistro($record);
        }
    }

    private function procesarRegistro(ResultadoScraping $record): void
    {
        try {
            $prompt = $this->builder->filtroPEP(
                $record->contexto ?? '',
                $record->pais ?? '',
                $record->categoria ?? '',
            );

            $response = $this->gemini->send($prompt, config('services.gemini.flash_model'));

            $dto = FiltroResultadoDTO::fromArray($response);

            $this->persistirResultado($record, $dto);
        } catch (GeminiInvalidResponseException|GeminiBadRequestException $e) {
            $this->marcarFallido($record, $e);
        }
    }

    private function persistirResultado(ResultadoScraping $record, FiltroResultadoDTO $dto): void
    {
        $normalizado = null;
        try {
            $normalizacion = $this->normalizador->normalizeNullable($dto->nombre);
            $normalizado = $normalizacion?->normalized;
        } catch (\Throwable $e) {
            Log::warning('Name normalization failed in GeminiFiltroService', [
                'nombre' => $dto->nombre,
                'error' => $e->getMessage(),
            ]);
        }

        $minConfianza = (int) config('services.gemini.min_confianza_pep', 70);
        $isPep = $dto->isPep;
        $motivo = $dto->motivo;

        if ($dto->isPep && $dto->confianza < $minConfianza) {
            $isPep = false;
            $motivo = "[THRESHOLD] Confianza {$dto->confianza} < mínimo {$minConfianza}. Original: {$dto->motivo}";

            Log::channel('gemini')->warning('PEP descartado por threshold', [
                'record_id' => $record->id,
                'confianza' => $dto->confianza,
                'threshold' => $minConfianza,
            ]);
        }

        $record->update([
            'gemini_analyzed' => true,
            'gemini_is_pep' => $isPep,
            'gemini_nombre' => $dto->nombre,
            'gemini_nombre_normalizado' => $normalizado,
            'gemini_cargo' => $dto->cargo,
            'gemini_categoria' => $dto->categoria,
            'gemini_entidad_tipo' => $dto->entidadTipo,
            'gemini_confianza' => $dto->confianza,
            'gemini_motivo' => $motivo,
        ]);

        Log::channel('gemini')->info('FiltroPEP completado', [
            'record_id' => $record->id,
            'is_pep' => $isPep,
            'categoria' => $dto->categoria,
            'confianza' => $dto->confianza,
        ]);
    }

    private function marcarFallido(ResultadoScraping $record, \Throwable $e): void
    {
        $record->update([
            'gemini_analyzed' => true,
        ]);

        Log::channel('gemini')->warning('FiltroPEP fallido, registro marcado', [
            'record_id' => $record->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
