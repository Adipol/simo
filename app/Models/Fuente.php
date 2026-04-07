<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fuente extends Model
{
    protected $table = 'fuentes';

    // Solo tiene created_at, sin updated_at (compatible con el script Python)
    const UPDATED_AT = null;

    protected $fillable = [
        'url', 'nombre', 'pais', 'organismo', 'nivel', 'tipo',
        'activo', 'selector_css', 'ultimo_check',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'ultimo_check' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function paisRelacion(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais', 'codigo');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'fuente_id');
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(Cambio::class, 'fuente_id');
    }

    public function ultimoSnapshot(): ?Snapshot
    {
        return $this->snapshots()->latest('fecha')->first();
    }

    public function cambiosSinRevisar(): int
    {
        return $this->cambios()->where('revisado', false)->count();
    }
}
