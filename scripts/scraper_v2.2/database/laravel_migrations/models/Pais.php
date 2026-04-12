<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pais extends Model
{
    protected $table = 'paises';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // ── Relaciones ────────────────────────────────────────────

    public function sitios(): HasMany
    {
        return $this->hasMany(SitioWeb::class, 'pais', 'codigo');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoScraping::class, 'pais', 'codigo');
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(
            PalabraClave::class,
            'keyword_paises',
            'pais',
            'keyword_id',
            'codigo',
            'id'
        );
    }

    // ── Scopes ────────────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}
