<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Services\Gemini\DTOs\FiltroResultadoDTO;
use App\Services\Gemini\DTOs\PersonaDetectadaDTO;
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
                    'nombre' => $persona->nombre,
                    'confianza' => $persona->confianza,
                    'threshold' => $minConfianza,
                ]);
            }

            $normalizado = $this->normalizarNombre($persona->nombre);

            ResultadoPersona::create([
                'resultado_scraping_id' => $record->id,
                'nombre' => $persona->nombre,
                'nombre_normalizado' => $normalizado,
                'cargo' => $persona->cargo,
                'categoria' => $persona->categoria,
                'entidad_tipo' => $persona->entidadTipo,
                'confianza' => $persona->confianza,
                'evento' => $persona->evento,
                'motivo' => $persona->motivo,
                'threshold_passed' => $thresholdPassed,
            ]);
        }

        // Update the parent record
        $record->update([
            'gemini_analyzed' => true,
            'gemini_is_pep' => $anyPepPassed,
            'gemini_motivo' => $dto->motivoGeneral,
        ]);

        Log::channel('gemini')->info('FiltroPEP completado', [
            'record_id' => $record->id,
            'personas_detectadas' => count($dto->personas),
            'pep_passed' => $anyPepPassed,
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
