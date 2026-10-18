<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Fuente extends Model
{
    use HasFactory;

    protected $table = 'fuentes';

    // Solo tiene created_at, sin updated_at (compatible con el script Python)
    const UPDATED_AT = null;

    protected $fillable = [
        'url', 'nombre', 'pais', 'organismo', 'nivel', 'tipo',
        'activo', 'selector_css', 'autoridades_extractor', 'ultimo_check', 'analizar_imagenes',
        'authority_extraction_config', 'authority_extraction_revision', 'authority_extraction_confirmed_revision',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'analizar_imagenes' => 'boolean',
        'ultimo_check' => 'datetime',
        'created_at' => 'datetime',
        'authority_extraction_config' => 'array',
    ];

    public function paisRelacion(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais', 'codigo');
    }

    public function authorityExtractionPreview(): HasOne
    {
        return $this->hasOne(AuthorityExtractionPreview::class, 'fuente_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'fuente_id');
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(Cambio::class, 'fuente_id');
    }

    public function revisionesRemocionAutoridades(): HasMany
    {
        return $this->hasMany(AuthorityRemovalReview::class, 'fuente_id');
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
