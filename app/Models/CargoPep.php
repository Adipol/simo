<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntidadTipo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CargoPep extends Model
{
    protected $table = 'cargos_pep';

    protected $fillable = [
        'pais_codigo',
        'nombre',
        'categoria',
        'entidad_tipo',
        'activo',
    ];

    protected $casts = [
        'entidad_tipo' => EntidadTipo::class,
        'activo' => 'boolean',
    ];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais_codigo', 'codigo');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeForCountry(Builder $query, string $code): Builder
    {
        return $query->where('pais_codigo', $code);
    }

    public function scopeByEntidadTipo(Builder $query, EntidadTipo $tipo): Builder
    {
        return $query->where('entidad_tipo', $tipo->value);
    }
}
