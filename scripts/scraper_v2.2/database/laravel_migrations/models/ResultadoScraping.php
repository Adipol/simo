<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultadoScraping extends Model
{
    protected $table = 'resultados_scraping';

    // El scraper Python gestiona fecha_encontrado; Laravel no usa created_at/updated_at
    public $timestamps = false;

    protected $fillable = [
        'url', 'keyword', 'sitio_id', 'pais', 'categoria',
        'titulo', 'contexto', 'fecha_encontrado',
        'relevance_score', 'found_in_title',
        'leido', 'relevante', 'notas',
    ];

    protected $casts = [
        'fecha_encontrado' => 'datetime',
        'found_in_title' => 'boolean',
        'leido' => 'boolean',
        'relevante' => 'boolean',
        'relevance_score' => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────

    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class, 'sitio_id');
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais', 'codigo');
    }

    // ── Scopes ────────────────────────────────────────────────

    /** Solo resultados donde la keyword apareció en el título */
    public function scopeAltaRelevancia(Builder $query): Builder
    {
        return $query->where('found_in_title', true);
    }

    public function scopeNoLeidos(Builder $query): Builder
    {
        return $query->where('leido', false);
    }

    public function scopePais(Builder $query, string $codigo): Builder
    {
        return $query->where('pais', $codigo);
    }

    public function scopeCategoria(Builder $query, string $categoria): Builder
    {
        return $query->where('categoria', $categoria);
    }

    public function scopeKeyword(Builder $query, string $keyword): Builder
    {
        return $query->where('keyword', $keyword);
    }

    public function scopeRecientes(Builder $query, int $dias = 7): Builder
    {
        return $query->where('fecha_encontrado', '>=', now()->subDays($dias));
    }

    // ── Helpers ───────────────────────────────────────────────

    public function marcarLeido(): void
    {
        $this->update(['leido' => true]);
    }

    public function marcarRelevante(bool $valor): void
    {
        $this->update(['relevante' => $valor]);
    }

    public function getNivelRelevanciaAttribute(): string
    {
        if ($this->found_in_title) {
            return 'Alta';
        }
        if ($this->relevance_score >= 50) {
            return 'Media';
        }

        return 'Baja';
    }
}
