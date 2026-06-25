<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of Gemini API calls with token counts.
 *
 * No updated_at — this table is write-once.
 * Foreign keys to cambios and resultados_scraping are nullable
 * (nullOnDelete — row stays when the parent is deleted).
 */
class GeminiUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'gemini_usage_log';

    protected $fillable = [
        'model',
        'prompt_tokens',
        'completion_tokens',
        'thinking_tokens',
        'total_tokens',
        'request_type',
        'cambio_id',
        'resultado_scraping_id',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'thinking_tokens' => 'integer',
        'total_tokens' => 'integer',
        'created_at' => 'datetime',
    ];

    public function cambio(): BelongsTo
    {
        return $this->belongsTo(Cambio::class);
    }

    public function resultadoScraping(): BelongsTo
    {
        return $this->belongsTo(ResultadoScraping::class, 'resultado_scraping_id');
    }
}
