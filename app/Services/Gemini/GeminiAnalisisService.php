<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Models\Cambio;
use App\Services\Gemini\DTOs\AnalisisCambioDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GeminiAnalisisService
{
    public function __construct(
        private GeminiService $gemini,
        private GeminiPromptBuilder $builder,
    ) {}

    public function analizarLote(Collection $records): void
    {
        foreach ($records as $cambio) {
            $this->procesarCambio($cambio);
        }
    }

    private function procesarCambio(Cambio $cambio): void
    {
        try {
            $diff = $this->builder->truncarDiff($cambio->diff_texto ?? '');

            $fuente = $cambio->fuente;
            $fuenteNombre = $fuente?->nombre ?? '';
            $organismoNombre = $fuente?->organismo ?? '';

            $prompt = $this->builder->analisisCambio($diff, $fuenteNombre, $organismoNombre);

            $response = $this->gemini->send($prompt, config('services.gemini.pro_model'));

            $dto = AnalisisCambioDTO::fromArray($response);

            $this->persistirAnalisis($cambio, $dto);
        } catch (GeminiInvalidResponseException|GeminiBadRequestException $e) {
            $this->marcarFallido($cambio, $e);
        }
    }

    private function persistirAnalisis(Cambio $cambio, AnalisisCambioDTO $dto): void
    {
        $cambio->update([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => [
                'persona_removida' => $dto->personaRemovida,
                'persona_nueva' => $dto->personaNueva,
                'cargo' => $dto->cargo,
                'es_mae' => $dto->esMae,
                'riesgo' => $dto->riesgo,
                'analisis' => $dto->analisis,
            ],
        ]);

        Log::channel('gemini')->info('AnalisisCambio completado', [
            'cambio_id' => $cambio->id,
            'es_mae' => $dto->esMae,
            'riesgo' => $dto->riesgo,
        ]);
    }

    private function marcarFallido(Cambio $cambio, \Throwable $e): void
    {
        $cambio->update([
            'gemini_analyzed' => true,
        ]);

        Log::channel('gemini')->warning('AnalisisCambio fallido, registro marcado', [
            'cambio_id' => $cambio->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
