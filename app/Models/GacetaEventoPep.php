<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for gaceta_eventos_pep.
 *
 * Represents a single PEP appointment event extracted from a gazette decree.
 * Child of GacetaNorma (many events per norma).
 *
 * @property int         $id
 * @property int         $gaceta_norma_id
 * @property string      $pais                         ISO 3166-1 alpha-2 (denormalized)
 * @property string      $persona_nombre               As extracted from sumario
 * @property string      $persona_nombre_normalizado   Uppercase — used for trigram index
 * @property string      $cargo
 * @property string|null $cargo_categoria              Mapped from cargos_pep; null = not found
 * @property string|null $entidad
 * @property string      $tipo_evento                  designacion | cese
 * @property bool        $interino
 * @property string|null $cargo_referenciado           Appointee's permanent role mentioned in interim decree; null when not present
 * @property string      $estado_revision              pendiente | requiere_revision | aprobado | rechazado
 * @property int|null    $revisado_por                 Seam: user_id set by Livewire in PR3
 * @property \Illuminate\Support\Carbon|null $revisado_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class GacetaEventoPep extends Model
{
    protected $table = 'gaceta_eventos_pep';

    protected $fillable = [
        'gaceta_norma_id',
        'pais',
        'persona_nombre',
        'persona_nombre_normalizado',
        'cargo',
        'cargo_categoria',
        'entidad',
        'tipo_evento',
        'interino',
        'cargo_referenciado',
        'estado_revision',
        'revisado_por',
        'revisado_at',
    ];

    protected $casts = [
        'interino'    => 'boolean',
        'revisado_at' => 'datetime',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function gacetaNorma(): BelongsTo
    {
        return $this->belongsTo(GacetaNorma::class, 'gaceta_norma_id');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Events awaiting human review in the Livewire queue.
     */
    public function scopePendienteRevision(Builder $query): Builder
    {
        return $query->where('estado_revision', 'pendiente');
    }

    /**
     * Events filtered by country code.
     */
    public function scopePorPais(Builder $query, string $pais): Builder
    {
        return $query->where('pais', $pais);
    }
}
