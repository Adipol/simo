<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultadoPersona extends Model
{
    protected $table = 'resultado_personas';

    /** Minimum confianza (%) to display with a "high confidence" color. */
    private const CONFIANZA_THRESHOLD = 70;

    protected $fillable = [
        'resultado_scraping_id', 'nombre', 'nombre_normalizado',
        'cargo', 'categoria', 'entidad_tipo', 'confianza',
        'evento', 'motivo', 'threshold_passed',
    ];

    protected $casts = [
        'confianza' => 'integer',
        'threshold_passed' => 'boolean',
    ];

    public function resultado(): BelongsTo
    {
        return $this->belongsTo(ResultadoScraping::class, 'resultado_scraping_id');
    }

    // =========================================================================
    // Presentation helpers
    // =========================================================================

    /**
     * Returns the Tailwind color class for the confianza percentage badge.
     * Threshold: >= 70 → emerald, < 70 → amber.
     */
    public function getConfianzaColorClass(): string
    {
        return ($this->confianza ?? 0) >= self::CONFIANZA_THRESHOLD ? 'text-emerald-600' : 'text-amber-600';
    }
}
