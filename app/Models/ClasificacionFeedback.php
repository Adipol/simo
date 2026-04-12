<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoriaCorreccion;
use App\Enums\TipoFeedback;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClasificacionFeedback extends Model
{
    /** @use HasFactory<\Database\Factories\ClasificacionFeedbackFactory> */
    use HasFactory;

    protected $table = 'clasificaciones_feedback';

    protected $fillable = [
        'resultado_scraping_id',
        'usuario_id',
        'tipo',
        'clasificacion_snapshot',
        'corregido_is_pep',
        'corregido_categoria',
        'corregido_nombre',
        'corregido_nombre_normalizado',
        'corregido_cargo',
        'motivo',
    ];

    protected $casts = [
        'clasificacion_snapshot' => 'array',
        'corregido_is_pep' => 'boolean',
        'tipo' => TipoFeedback::class,
        'corregido_categoria' => CategoriaCorreccion::class,
    ];

    public function resultadoScraping(): BelongsTo
    {
        return $this->belongsTo(ResultadoScraping::class, 'resultado_scraping_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function scopeCorrectos($query): void
    {
        $query->where('tipo', TipoFeedback::Correcto);
    }

    public function scopeIncorrectos($query): void
    {
        $query->where('tipo', TipoFeedback::Incorrecto);
    }

    public function scopePorUsuario($query, int $userId): void
    {
        $query->where('usuario_id', $userId);
    }

    public function scopePorResultado($query, int $resultadoId): void
    {
        $query->where('resultado_scraping_id', $resultadoId);
    }
}
