<?php

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
        'leido', 'relevante', 'notas',
    ];

    protected $casts = [
        'found_in_title' => 'boolean',
        'leido' => 'boolean',
        'relevante' => 'boolean',
        'fecha_encontrado' => 'datetime',
        'relevance_score' => 'integer',
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
