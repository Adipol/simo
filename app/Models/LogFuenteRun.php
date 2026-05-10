<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LogFuenteRun extends Model
{
    /**
     * No auto-managed timestamps — rows are immutable.
     * started_at is the creation timestamp; finished_at is set explicitly.
     */
    public $timestamps = false;

    protected $table = 'log_fuente_runs';

    protected $fillable = [
        'fuente_id',
        'started_at',
        'finished_at',
        'estado',
        'http_status',
        'cambios_detectados',
        'error_mensaje',
        'duracion_segundos',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'http_status' => 'integer',
        'cambios_detectados' => 'integer',
        'duracion_segundos' => 'float',
    ];

    /**
     * Valid estado values — app-level validation; no DB check constraint.
     *
     * @var array<string>
     */
    public const VALID_ESTADOS = [
        'success',
        'http_error',
        'timeout',
        'captcha',
        'parse_error',
        'other',
    ];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }
}
