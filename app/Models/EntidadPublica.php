<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntidadPublica extends Model
{
    protected $table = 'entidades_publicas';

    protected $fillable = [
        'pais_codigo',
        'nombre',
        'sigla',
        'activo',
    ];

    protected $casts = [
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
}
