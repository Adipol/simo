<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Models\GeminiUsageLog;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\FiltroResultadoDTO;
use App\Services\Gemini\DTOs\GeminiResponseDTO;
use App\Services\Normalization\NombreNormalizador;
use App\Services\Normalization\NombreNormalizadorInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiFiltroService
{
    public function __construct(
        private GeminiService $gemini,
        private GeminiPromptBuilder $builder,
        private PreFiltroService $preFiltro = new PreFiltroService,
        private NombreNormalizadorInterface $normalizador = new NombreNormalizador,
    ) {}

    public function analizarLote(Collection $records): void
    {
        foreach ($records as $record) {
            // Idempotency guard: skip already-analyzed records.
            if ($record->gemini_analyzed_at !== null) {
                continue;
            }

            $this->procesarRegistro($record);
        }
    }

    private function procesarRegistro(ResultadoScraping $record): void
    {
        if (! $this->preFiltro->shouldAnalyzeWithGemini($record)) {
            $record->update([
                'gemini_analyzed'    => true,
                'gemini_analyzed_at' => now(),
                'gemini_is_pep'      => false,
                'gemini_motivo'      => '[PRE-FILTRO] Sin mención de cargo público en el texto.',
            ]);

            return;
        }

        try {
            $prompt = $this->builder->filtroPEP(
                $record->contexto ?? '',
                $record->pais ?? '',
                $record->categoria ?? '',
            );

            $model = config('services.gemini.flash_model');

            $geminiResponse = $this->gemini->sendWithMetadata($prompt, $model);

            $dto = FiltroResultadoDTO::fromArray($geminiResponse->content);

            $this->persistirResultado($record, $dto, $geminiResponse, $model);
        } catch (GeminiInvalidResponseException|GeminiBadRequestException $e) {
            $this->marcarFallido($record, $e, $e->getMessage());
        }
    }

    private function persistirResultado(
        ResultadoScraping $record,
        FiltroResultadoDTO $dto,
        ?GeminiResponseDTO $geminiResponse = null,
        string $model = '',
    ): void {
        $minConfianza = (int) config('services.gemini.min_confianza_pep', 70);
        $anyPepPassed = false;

        // Save each persona detected
        foreach ($dto->personas as $persona) {
            $thresholdPassed = $persona->confianza >= $minConfianza;

            if ($thresholdPassed) {
                $anyPepPassed = true;
            } else {
                Log::channel('gemini')->warning('Persona descartada por threshold', [
                    'record_id' => $record->id,
                    'nombre'    => $persona->nombre,
                    'confianza' => $persona->confianza,
                    'threshold' => $minConfianza,
                ]);
            }

            $normalizado = $this->normalizarNombre($persona->nombre);

            ResultadoPersona::create([
                'resultado_scraping_id' => $record->id,
                'nombre'                => $persona->nombre,
                'nombre_normalizado'    => $normalizado,
                'cargo'                 => $persona->cargo,
                'categoria'             => $persona->categoria,
                'entidad_tipo'          => $persona->entidadTipo,
                'confianza'             => $persona->confianza,
                'evento'                => $persona->evento,
                'motivo'                => $persona->motivo,
                'threshold_passed'      => $thresholdPassed,
            ]);
        }

        // Update the parent record with timestamp
        $record->update([
            'gemini_analyzed'    => true,
            'gemini_analyzed_at' => now(),
            'gemini_is_pep'      => $anyPepPassed,
            'gemini_motivo'      => $dto->motivoGeneral,
        ]);

        // Write usage log
        if ($geminiResponse !== null) {
            if (! $geminiResponse->hasUsageMetadata()) {
                Log::channel('gemini')->warning('FiltroPEP: usageMetadata ausente en respuesta Gemini', [
                    'record_id' => $record->id,
                ]);
            }

            // Must NEVER break the analysis result
            try {
                GeminiUsageLog::create([
                    'model'                 => $model,
                    'prompt_tokens'         => $geminiResponse->promptTokens(),
                    'completion_tokens'     => $geminiResponse->completionTokens(),
                    'total_tokens'          => $geminiResponse->totalTokens(),
                    'request_type'          => 'filtro',
                    'cambio_id'             => null,
                    'resultado_scraping_id' => $record->id,
                ]);
            } catch (\Throwable $e) {
                Log::channel('gemini')->error('FiltroPEP: error al insertar gemini_usage_log (análisis guardado)', [
                    'record_id' => $record->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Log::channel('gemini')->info('FiltroPEP completado', [
            'record_id'           => $record->id,
            'personas_detectadas' => count($dto->personas),
            'pep_passed'          => $anyPepPassed,
        ]);
    }

    private function normalizarNombre(?string $nombre): ?string
    {
        if ($nombre === null || $nombre === '') {
            return null;
        }

        try {
            $normalizacion = $this->normalizador->normalizeNullable($nombre);

            return $normalizacion?->normalized;
        } catch (\Throwable $e) {
            Log::warning('Name normalization failed', [
                'nombre' => $nombre,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function marcarFallido(ResultadoScraping $record, \Throwable $e, ?string $motivo = null): void
    {
        $record->update([
            'gemini_analyzed' => true,
            'gemini_is_pep' => false,
            'gemini_error_motivo' => $motivo !== null ? Str::limit($motivo, 500, '') : null,
        ]);

        Log::channel('gemini')->warning('FiltroPEP fallido, registro marcado', [
            'resultado_id' => $record->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'motivo' => $motivo,
        ]);
    }
}
