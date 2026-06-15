<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for gaceta_normas.
 *
 * Represents a single legal norm/decree collected from an official gazette.
 * One norma can have multiple PEP appointment events (hasMany GacetaEventoPep).
 *
 * @property int         $id
 * @property string      $pais                  ISO 3166-1 alpha-2
 * @property int         $gaceta_id_externo     Gazette's internal ID — cursor for incremental collection
 * @property string|null $numero_decreto
 * @property string      $tipo_norma
 * @property string|null $edicion
 * @property \Illuminate\Support\Carbon|null $fecha_publicacion
 * @property string      $sumario
 * @property string|null $texto_completo
 * @property string|null $pdf_url
 * @property string|null $pdf_archivado_path
 * @property array|null  $raw_json
 * @property string      $estado_extraccion
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class GacetaNorma extends Model
{
    protected $table = 'gaceta_normas';

    protected $fillable = [
        'pais',
        'gaceta_id_externo',
        'numero_decreto',
        'tipo_norma',
        'edicion',
        'fecha_publicacion',
        'sumario',
        'texto_completo',
        'pdf_url',
        'pdf_archivado_path',
        'raw_json',
        'estado_extraccion',
    ];

    protected $casts = [
        'fecha_publicacion' => 'date',
        'raw_json'          => 'array',
        'gaceta_id_externo' => 'integer',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function eventosPep(): HasMany
    {
        return $this->hasMany(GacetaEventoPep::class, 'gaceta_norma_id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Normas whose sumario has not been processed by the extractor yet.
     */
    public function scopePendienteExtraccion(Builder $query): Builder
    {
        return $query->where('estado_extraccion', 'pendiente');
    }

    /**
     * Normas flagged as needing manual detail extraction (bulk summaries,
     * multi-inciso decrees that the regex could not fully resolve).
     */
    public function scopeRequiereRevision(Builder $query): Builder
    {
        return $query->where('estado_extraccion', 'requiere_detalle');
    }
}
