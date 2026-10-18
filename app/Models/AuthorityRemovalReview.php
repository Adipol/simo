<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthorityRemovalReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuthorityRemovalReview extends Model
{
    protected $table = 'revisiones_remocion_autoridades';

    protected $fillable = [
        'fuente_id', 'snapshot_base_id', 'snapshot_observado_id', 'origen', 'version_esquema',
        'linea_base_json', 'candidato_json', 'eventos_propuestos_json', 'evidencia_json',
        'fingerprint', 'lifecycle_key', 'estado', 'decidido_por', 'decidido_at', 'evidencia_decision_json',
        'cambio_confirmado_id', 'analisis_despachado_at',
    ];

    protected function casts(): array
    {
        return [
            'estado' => AuthorityRemovalReviewStatus::class,
            'lifecycle_key' => 'integer',
            'linea_base_json' => 'array',
            'candidato_json' => 'array',
            'eventos_propuestos_json' => 'array',
            'evidencia_json' => 'array',
            'evidencia_decision_json' => 'array',
            'decidido_at' => 'datetime',
            'analisis_despachado_at' => 'datetime',
        ];
    }

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }

    public function snapshotBase(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'snapshot_base_id');
    }

    public function snapshotObservado(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'snapshot_observado_id');
    }

    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    public function cambioConfirmado(): BelongsTo
    {
        return $this->belongsTo(Cambio::class, 'cambio_confirmado_id');
    }
}
