<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pais extends Model
{
    protected $table = 'paises';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function sitiosWeb(): HasMany
    {
        return $this->hasMany(SitioWeb::class, 'pais', 'codigo');
    }

    public function fuentes(): HasMany
    {
        return $this->hasMany(Fuente::class, 'pais', 'codigo');
    }
}
