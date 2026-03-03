<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PalabraClave extends Model
{
    protected $table = 'palabras_clave';
    public $timestamps = false;

    protected $fillable = ['keyword', 'categoria', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
        'fecha_creacion' => 'datetime',
    ];

    public function resultados(): HasMany
    {
        return $this->hasMany(ResultadoScraping::class, 'keyword', 'keyword');
    }
}
