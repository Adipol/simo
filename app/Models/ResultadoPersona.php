<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultadoPersona extends Model
{
    protected $table = 'resultado_personas';

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
}
