<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultadoScraping extends Model
{
    /** @use HasFactory<\Database\Factories\ResultadoScrapingFactory> */
    use HasFactory;

    protected $table = 'resultados_scraping';

    public $timestamps = false;

    protected $fillable = [
        'url', 'keyword', 'sitio_id', 'pais', 'categoria', 'titulo', 'contexto',
        'fecha_encontrado', 'relevance_score', 'found_in_title',
        'leido', 'relevante', 'descartado', 'archivado_at', 'notas',
        'gemini_analyzed', 'gemini_analyzed_at', 'gemini_is_pep', 'gemini_error_motivo',
        'gemini_nombre', 'gemini_nombre_normalizado', 'gemini_cargo',
        'gemini_categoria', 'gemini_entidad_tipo', 'gemini_confianza', 'gemini_motivo',
        'secundario_de',
    ];

    protected $casts = [
        'found_in_title'     => 'boolean',
        'leido'              => 'boolean',
        'relevante'          => 'boolean',
        'descartado'         => 'boolean',
        'archivado_at'       => 'datetime',
        'fecha_encontrado'   => 'datetime',
        'relevance_score'    => 'integer',
        'gemini_analyzed'    => 'boolean',
        'gemini_analyzed_at' => 'datetime',
        'gemini_is_pep'      => 'boolean',
        'gemini_confianza'   => 'integer',
        'secundario_de'      => 'integer',
    ];

    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class, 'sitio_id');
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais', 'codigo');
    }

    public function personas(): HasMany
    {
        return $this->hasMany(ResultadoPersona::class, 'resultado_scraping_id');
    }

    /**
     * The primary article this article is a duplicate of.
     * Returns null when secundario_de IS NULL (this article is itself a primary).
     */
    public function primary(): BelongsTo
    {
        return $this->belongsTo(self::class, 'secundario_de');
    }

    /**
     * Secondary (duplicate) articles clustered under this primary.
     * Design D3: self-referential FK cluster model.
     */
    public function secondaries(): HasMany
    {
        return $this->hasMany(self::class, 'secundario_de');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeArchivado(Builder $query): void
    {
        $query->whereNotNull('archivado_at');
    }

    public function scopeNoArchivado(Builder $query): void
    {
        $query->whereNull('archivado_at');
    }

    /**
     * Filter to only primary articles (not duplicates).
     * Excludes articles that have been marked as secondary (secundario_de IS NOT NULL).
     */
    public function scopeOnlyPrimaries(Builder $query): void
    {
        $query->whereNull('secundario_de');
    }

    /**
     * Filter to only secondary (duplicate) articles.
     * Includes articles that have been linked to a primary (secundario_de IS NOT NULL).
     *
     * NOTE: Named scopeOnlySecondaries (not scopeSecondaries) to avoid collision with
     * the secondaries() HasMany relation — PHP would resolve the static call ambiguously.
     * Usage: ResultadoScraping::onlySecondaries()->count()
     */
    public function scopeOnlySecondaries(Builder $query): void
    {
        $query->whereNotNull('secundario_de');
    }

    // =========================================================================
    // Presentation helpers
    // =========================================================================

    /**
     * Returns the Tailwind color class for the relevance score badge.
     * Thresholds: >= 70 → emerald, >= 40 → amber, < 40 (or null) → gray.
     */
    public function getScoreColorClass(): string
    {
        $score = $this->relevance_score;

        if ($score === null) {
            return 'text-gray-300';
        }

        if ($score >= 70) {
            return 'text-emerald-600';
        }

        if ($score >= 40) {
            return 'text-amber-500';
        }

        return 'text-gray-300';
    }
}
