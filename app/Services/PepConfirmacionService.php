<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoPersona;
use App\Models\ResultadoScraping;
use App\Services\Normalization\NombreNormalizador;
use Illuminate\Support\Facades\DB;

final class PepConfirmacionService
{
    public function __construct(
        private readonly NombreNormalizador $normalizador,
    ) {}

    public function confirmar(
        ResultadoScraping $resultado,
        int $usuarioId,
        string $nombre,
        ?string $cargo = null,
        ?string $evento = null,
    ): void {
        $normResult = $this->normalizador->normalize($nombre);
        $normalized = $normResult->normalized;
        $normalizedKey = $normResult->matchingKey;

        DB::transaction(function () use ($resultado, $usuarioId, $nombre, $cargo, $evento, $normalized, $normalizedKey): void {
            ResultadoPersona::create([
                'resultado_scraping_id' => $resultado->id,
                'nombre' => $nombre,
                'nombre_normalizado' => $normalized,
                'cargo' => $cargo,
                'categoria' => 'PEP',
                'entidad_tipo' => 'desconocido',
                'confianza' => 100,
                'threshold_passed' => true,
                'evento' => $evento,
            ]);

            $resultado->update([
                'gemini_is_pep' => true,
                'gemini_analyzed' => true,
                'gemini_categoria' => 'PEP',
                'gemini_nombre' => $nombre,
                'gemini_nombre_normalizado' => $normalizedKey,
                'gemini_cargo' => $cargo,
            ]);

            ClasificacionFeedback::create([
                'resultado_scraping_id' => $resultado->id,
                'usuario_id' => $usuarioId,
                'tipo' => TipoFeedback::Correcto,
                'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
                'corregido_is_pep' => true,
                'corregido_categoria' => 'PEP',
                'corregido_nombre' => $nombre,
                'corregido_nombre_normalizado' => $normalizedKey,
                'corregido_cargo' => $cargo,
            ]);
        });
    }
}
