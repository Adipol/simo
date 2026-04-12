<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PalabraClave extends Model
{
    protected $table = 'palabras_clave';

    // Solo created_at, sin updated_at
    const UPDATED_AT = null;

    protected $fillable = ['keyword', 'categoria', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // ── Relaciones ────────────────────────────────────────────

    /**
     * Países asignados a esta keyword.
     * Si no tiene ninguno = es GLOBAL (aplica a todos los países).
     */
    public function paises(): BelongsToMany
    {
        return $this->belongsToMany(
            Pais::class,
            'keyword_paises',
            'keyword_id',
            'pais',
            'id',
            'codigo'
        );
    }

    // ── Scopes ────────────────────────────────────────────────

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    public function scopeCategoria($query, string $categoria)
    {
        return $query->where('categoria', $categoria);
    }

    /** Keywords sin país asignado = aplican a todos */
    public function scopeGlobales($query)
    {
        return $query->whereDoesntHave('paises');
    }

    /** Keywords que aplican a un país específico (globales + asignadas al país) */
    public function scopeParaPais($query, string $codigo)
    {
        return $query->where(function ($q) use ($codigo) {
            $q->whereDoesntHave('paises')
                ->orWhereHas('paises', fn ($p) => $p->where('codigo', $codigo));
        });
    }

    // ── Helpers ───────────────────────────────────────────────

    public function esGlobal(): bool
    {
        return $this->paises->isEmpty();
    }
}
