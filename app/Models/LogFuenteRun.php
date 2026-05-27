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
     * All estado values the Python scraper (pep_monitor.py) can write.
     * Order preserved + extended with the 3 missing healthy/ambiguous states
     * (`no_change`, `no_content`, `first_snapshot`).
     *
     * Note: the predecessor spec also lists `exception` as an exit path; in
     * the actual Python code this maps to the catch-all `other` branch
     * (pep_monitor.py:1636). `exception` is a spec alias only — not a
     * separate runtime value, so it is NOT added here.
     *
     * @var array<string>
     */
    public const VALID_ESTADOS = [
        'success',
        'no_change',
        'first_snapshot',
        'no_content',
        'http_error',
        'timeout',
        'captcha',
        'parse_error',
        'ssl_error',
        'other',
    ];

    /**
     * Estado values that count as a healthy run (break the consecutive-failure
     * streak and qualify as `last_ok_at`). Strict subset of VALID_ESTADOS.
     *
     * Single source of truth for healthy-estado semantics — referenced from
     * DashboardSourceHealthService. Unknown/future states fail safe (counted
     * as failure until explicitly whitelisted here).
     *
     * @var array<string>
     */
    public const HEALTHY_ESTADOS = [
        'success',
        'no_change',
        'first_snapshot',
    ];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }
}
