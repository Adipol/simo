<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiteValidationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitioWeb extends Model
{
    /** @use HasFactory<\Database\Factories\SitioWebFactory> */
    use HasFactory;

    protected $table = 'sitios_web';

    public $timestamps = false;

    protected $fillable = [
        'url', 'nombre', 'pais', 'selector_links', 'selector_article', 'activo',
        'validation_status', 'activation_requested', 'validation_token',
        'validation_requested_at', 'validation_started_at', 'validated_at',
        'validation_diagnostic', 'validation_resolved_url',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'activation_requested' => 'boolean',
        'validation_status' => SiteValidationStatus::class,
        'validation_requested_at' => 'datetime',
        'validation_started_at' => 'datetime',
        'validated_at' => 'datetime',
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
}
