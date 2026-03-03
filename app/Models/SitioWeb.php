<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SitioWeb extends Model
{
    protected $table = 'sitios_web';
    public $timestamps = false;

    protected $fillable = [
        'url', 'nombre', 'pais', 'selector_links', 'selector_article', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'fecha_creacion' => 'datetime',
        'fecha_modificacion' => 'datetime',
    ];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais', 'codigo');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoScraping::class, 'sitio_id');
    }

    public function palabrasClave(): BelongsToMany
    {
        return $this->belongsToMany(PalabraClave::class, 'keyword_paises', 'sitio_id', 'keyword_id');
    }
}
