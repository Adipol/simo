<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuthorityExtractionPreview extends Model
{
    protected $fillable = [
        'fuente_id', 'source_revision', 'source_url', 'status', 'attempts',
        'lease_token', 'lease_expires_at', 'config_fingerprint', 'authorities_json',
        'error_code', 'requested_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'authorities_json' => 'array',
            'lease_expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }
}
