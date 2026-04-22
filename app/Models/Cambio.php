<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cambio extends Model
{
    protected $table = 'cambios';

    public $timestamps = false;

    protected $fillable = [
        'fuente_id', 'fecha', 'hash_anterior', 'hash_nuevo',
        'lineas_quitadas', 'lineas_nuevas', 'diff_texto',
        'posibles_peps', 'revisado',
        'gemini_analyzed', 'gemini_analisis_json',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'revisado' => 'boolean',
        'gemini_analyzed' => 'boolean',
        'gemini_analisis_json' => 'array',
    ];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }

    public static function marcarComoRevisado(int $id): void
    {
        static::where('id', $id)->update(['revisado' => true]);
    }

    /**
     * Devuelve el diff como array de lineas con tipo (added/removed/context).
     */
    public function parsedDiff(): array
    {
        if (! $this->diff_texto) {
            return [];
        }

        $lines = [];
        foreach (explode("\n", $this->diff_texto) as $line) {
            if (str_starts_with($line, '+')) {
                $lines[] = ['type' => 'added', 'text' => substr($line, 1)];
            } elseif (str_starts_with($line, '-')) {
                $lines[] = ['type' => 'removed', 'text' => substr($line, 1)];
            } else {
                $lines[] = ['type' => 'context', 'text' => $line];
            }
        }

        return $lines;
    }

    /**
     * Indica si el cambio debería mostrarse con estilo atenuado (sin persona detectada).
     */
    public function esMuted(): bool
    {
        return $this->gemini_analyzed
            && ($this->gemini_analisis_json['persona_nueva'] ?? null) === null
            && ($this->gemini_analisis_json['persona_removida'] ?? null) === null;
    }

    /**
     * Scope: solo cambios con persona detectada por Gemini.
     */
    public function scopeConPersona(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('gemini_analyzed', true)
            ->where(function (\Illuminate\Database\Eloquent\Builder $sub): void {
                $sub->whereRaw("gemini_analisis_json->>'persona_nueva' IS NOT NULL")
                    ->orWhereRaw("gemini_analisis_json->>'persona_removida' IS NOT NULL");
            });
    }

    /**
     * Scope: solo cambios sin persona detectada por Gemini.
     */
    public function scopeSinPersona(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('gemini_analyzed', true)
            ->whereRaw("(gemini_analisis_json->>'persona_nueva' IS NULL AND gemini_analisis_json->>'persona_removida' IS NULL)");
    }

    /**
     * Scope: filtrar por nivel de riesgo en el análisis Gemini.
     */
    public function scopeConRiesgo(\Illuminate\Database\Eloquent\Builder $query, string $riesgo): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('gemini_analyzed', true)
            ->whereRaw("gemini_analisis_json->>'riesgo' = ?", [$riesgo]);
    }

    /**
     * Devuelve posibles PEPs como array.
     */
    public function posiblesPepsArray(): array
    {
        if (! $this->posibles_peps) {
            return [];
        }

        return array_filter(explode("\n", $this->posibles_peps));
    }
}
