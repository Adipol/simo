<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultadoScraping extends Model
{
    protected $table = 'resultados_scraping';

    public $timestamps = false;

    protected $fillable = [
        'url', 'keyword', 'sitio_id', 'pais', 'categoria', 'titulo', 'contexto',
        'fecha_encontrado', 'relevance_score', 'found_in_title',
        'leido', 'relevante', 'descartado', 'notas',
        'gemini_analyzed', 'gemini_is_pep', 'gemini_nombre', 'gemini_cargo',
        'gemini_categoria', 'gemini_confianza', 'gemini_motivo',
    ];

    protected $casts = [
        'found_in_title' => 'boolean',
        'leido' => 'boolean',
        'relevante' => 'boolean',
        'descartado' => 'boolean',
        'fecha_encontrado' => 'datetime',
        'relevance_score' => 'integer',
        'gemini_analyzed' => 'boolean',
        'gemini_is_pep' => 'boolean',
        'gemini_confianza' => 'integer',
    ];

    public function sitio(): BelongsTo
    {
        return $this->belongsTo(SitioWeb::class, 'sitio_id');
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais', 'codigo');
    }
}
