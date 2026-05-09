<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\Gemini\GeminiBadRequestException;
use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Exceptions\Gemini\GeminiPayloadTooLargeException;
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
        $multimodalEnabled = (bool) config('services.gemini.multimodal_enabled', true);

        // Eager-load fuente para evitar N+1 dentro del loop (solo si es EloquentCollection)
        if ($records instanceof \Illuminate\Database\Eloquent\Collection) {
            $records->loadMissing('fuente');
        }

        foreach ($records as $cambio) {
            // Triple condición: kill switch global + cambio con imágenes + fuente con toggle ON.
            // Esto protege a cambios huérfanos: si la fuente apagó analizar_imagenes,
            // los cambios viejos con imágenes en JSON van a text-only (no consumen tokens
            // de multimodal innecesariamente).
            $fuenteAnalizaImagenes = (bool) ($cambio->fuente?->analizar_imagenes ?? false);

            if ($multimodalEnabled && $cambio->tieneImagenes() && $fuenteAnalizaImagenes) {
                $this->procesarCambioMultimodal($cambio);
            } else {
                $this->procesarCambio($cambio);
            }
        }
    }

    private function procesarCambio(Cambio $cambio): void
    {
        try {
            $diff = $cambio->diff_texto ?? '';

            // Guard anti-alucinación: si no hay diff de texto, NO llamamos a Gemini.
            // Sin input concreto el modelo tiende a fabricar nombres y eventos.
            if (trim($diff) === '') {
                $this->persistirAnalisis(
                    $cambio,
                    AnalisisCambioDTO::sinNovedad('Cambio sin diff de texto: no se analizó.'),
                );

                return;
            }

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

    private function procesarCambioMultimodal(Cambio $cambio): void
    {
        try {
            $diff = $cambio->diff_texto ?? '';

            $fuente = $cambio->fuente;
            $fuenteNombre = $fuente?->nombre ?? '';
            $organismoNombre = $fuente?->organismo ?? '';

            $imagenes = $this->resolverImagenes($cambio);

            // Guard anti-alucinación: sin diff Y sin imágenes válidas no hay nada que analizar.
            // Llamar a Gemini con input vacío es la causa raíz de respuestas inventadas.
            if (trim($diff) === '' && empty($imagenes)) {
                $this->persistirAnalisis(
                    $cambio,
                    AnalisisCambioDTO::sinNovedad('Cambio sin diff ni imágenes válidas: no se analizó.'),
                );

                return;
            }

            if (empty($imagenes)) {
                // Degrade to text-only if no readable images
                Log::channel('gemini')->warning('procesarCambioMultimodal: no readable images, degrading to text-only', [
                    'cambio_id' => $cambio->id,
                ]);
                $this->procesarCambio($cambio);

                return;
            }

            $prompt = $this->builder->analisisCambioMultimodal(
                $diff,
                $fuenteNombre,
                $organismoNombre,
                count($imagenes),
            );

            $visionModel = config('services.gemini.vision_model', config('services.gemini.pro_model'));

            Log::channel('gemini')->info('AnalisisCambio multimodal iniciado', [
                'cambio_id' => $cambio->id,
                'image_count' => count($imagenes),
            ]);

            $response = $this->gemini->sendMultimodal($prompt, $imagenes, $visionModel);

            $dto = AnalisisCambioDTO::fromArray($response);

            $this->persistirAnalisis($cambio, $dto);

            Log::channel('gemini')->info('procesarCambioMultimodal completado', [
                'cambio_id' => $cambio->id,
                'image_count' => count($imagenes),
                'es_mae' => $dto->esMae,
                'riesgo' => $dto->riesgo,
            ]);
        } catch (GeminiInvalidResponseException|GeminiBadRequestException|GeminiPayloadTooLargeException $e) {
            $this->marcarFallido($cambio, $e);
        }
    }

    /**
     * Resolve image entries from cambio JSON to absolute filesystem paths, filtering unreadable files.
     *
     * @return array<int,array{path:string,mime_type:string}>
     */
    private function resolverImagenes(Cambio $cambio): array
    {
        $entries = $cambio->imagenes_cambio_json ?? [];

        $resolved = [];

        foreach ($entries as $entry) {
            $absPath = storage_path('app/' . $entry['path']);

            if (! is_readable($absPath)) {
                Log::channel('gemini')->warning('resolverImagenes: image not readable, skipping', [
                    'cambio_id' => $cambio->id,
                    'path' => $absPath,
                ]);

                continue;
            }

            $resolved[] = [
                'path' => $absPath,
                'mime_type' => $entry['mime_type'],
            ];
        }

        return $resolved;
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
                'personas_detectadas' => $dto->personasDetectadas,
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
