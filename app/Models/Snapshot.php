<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Snapshot extends Model
{
    protected $table = 'snapshots';

    public $timestamps = false;

    protected $fillable = [
        'fuente_id', 'hash', 'texto', 'autoridades_json', 'metodo', 'fecha',
        'authority_extraction_config_fingerprint', 'authority_extraction_revision',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'autoridades_json' => 'array',
    ];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }
}
