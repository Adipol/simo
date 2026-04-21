<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategoriaCorreccion;
use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Services\Normalization\NombreNormalizador;

final class FeedbackIncorrectoService
{
    public function __construct(
        private readonly NombreNormalizador $normalizador,
    ) {}

    public function guardar(
        ResultadoScraping $resultado,
        int $usuarioId,
        string $categoriaCorregida,
        string $motivo,
        ?bool $isPepCorregido = null,
        ?string $nombreCorregido = null,
        ?string $cargoCorregido = null,
    ): void {
        $nombreNormalizado = $this->normalizador->normalizeNullable($nombreCorregido)?->normalized;

        ClasificacionFeedback::updateOrCreate(
            ['resultado_scraping_id' => $resultado->id, 'usuario_id' => $usuarioId],
            [
                'tipo' => TipoFeedback::Incorrecto,
                'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
                'corregido_is_pep' => $isPepCorregido,
                'corregido_categoria' => $categoriaCorregida ? CategoriaCorreccion::from($categoriaCorregida) : null,
                'corregido_nombre' => $nombreCorregido,
                'corregido_nombre_normalizado' => $nombreNormalizado,
                'corregido_cargo' => $cargoCorregido,
                'motivo' => $motivo,
            ]
        );
    }
}
