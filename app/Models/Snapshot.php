<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Snapshot extends Model
{
    protected $table = 'snapshots';
    public $timestamps = false;

    protected $fillable = ['fuente_id', 'hash', 'texto', 'metodo', 'fecha'];

    protected $casts = [
        'fecha' => 'datetime',
    ];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }
}
