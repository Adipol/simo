<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Cambio extends Model
{
    use HasFactory;

    protected $table = 'cambios';

    public $timestamps = false;

    protected $fillable = [
        'fuente_id', 'fecha', 'hash_anterior', 'hash_nuevo',
        'lineas_quitadas', 'lineas_nuevas', 'diff_texto',
        'posibles_peps', 'revisado', 'revisado_at',
        'gemini_analyzed', 'gemini_analyzed_at', 'gemini_analisis_json',
        'imagenes_cambio_json',
    ];

    protected $casts = [
        'fecha' => 'datetime',
        'revisado' => 'boolean',
        'revisado_at' => 'datetime',
        'gemini_analyzed' => 'boolean',
        'gemini_analyzed_at' => 'datetime',
        'gemini_analisis_json' => 'array',
        'imagenes_cambio_json' => 'array',
    ];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }

    /**
     * Indica si el cambio tiene imágenes adjuntas para análisis multimodal.
     */
    public function tieneImagenes(): bool
    {
        return is_array($this->imagenes_cambio_json) && count($this->imagenes_cambio_json) > 0;
    }

    /**
     * Scope: cambios con imágenes procesadas (para análisis multimodal).
     */
    public function scopeMultimodal(Builder $query): Builder
    {
        return $query->whereNotNull('imagenes_cambio_json')
            ->whereRaw($this->jsonArrayLength('imagenes_cambio_json').' > 0');
    }

    /**
     * Returns a driver-aware SQL expression for JSON array length of $column.
     *
     * Mirrors DashboardSummaryService::dateTruncDay() pattern: returns a raw
     * string fragment; caller concatenates comparison operator and wraps with whereRaw().
     */
    private function jsonArrayLength(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "jsonb_array_length({$column}::jsonb)",
            'sqlite' => "json_array_length({$column})",
            default => throw new \RuntimeException('Unsupported DB driver: '.DB::getDriverName()),
        };
    }

    /**
     * Returns a driver-aware SQL expression for extracting a scalar value from a JSON column.
     *
     * Mirrors DashboardSummaryService::heroCard() driver-branching pattern:
     * - pgsql: uses ->> operator (returns TEXT, NULL for missing or JSON null keys)
     * - sqlite: uses json_extract() (equivalent semantics on SQLite 3.38+)
     *
     * The helper is a pure accessor — type interpretation (casting, comparison operators)
     * is the caller's responsibility, mirroring how dateTruncDay() returns an expression
     * but the caller decides what comparison to apply.
     */
    private function jsonExtract(string $column, string $path): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "{$column}->>'$path'",
            'sqlite' => "json_extract({$column}, '$.{$path}')",
            default => throw new \RuntimeException('Unsupported DB driver: '.DB::getDriverName()),
        };
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
     *
     * Solo atenúa cuando Gemini analizó con éxito, confirmó riesgo bajo, sin personas,
     * y el scraper tampoco detectó posibles PEPs.
     */
    public function esMuted(): bool
    {
        return $this->gemini_analyzed
            && $this->gemini_analisis_json !== null
            && ($this->gemini_analisis_json['riesgo'] ?? null) === 'bajo'
            && ($this->gemini_analisis_json['persona_nueva'] ?? null) === null
            && ($this->gemini_analisis_json['persona_removida'] ?? null) === null
            && empty($this->posibles_peps);
    }

    /**
     * Scope: cambios con persona detectada.
     *
     * Regla:
     * - Si Gemini ya analizó, su veredicto es la verdad: solo coincide
     *   cuando detectó persona_nueva o persona_removida.
     * - Si Gemini todavía NO analizó (pending), se usa posibles_peps del
     *   scraper como señal provisoria (fallback).
     *
     * El scraper NO sobrescribe a Gemini: una vez que Gemini dictaminó
     * "no es persona", el cambio queda fuera de este scope aunque el
     * scraper haya marcado posibles_peps.
     */
    public function scopeConPersona(Builder $query): Builder
    {
        return $query->where(function (Builder $sub): void {
            // Rama 1: Gemini analizó y detectó persona.
            $sub->where(function (Builder $gemini): void {
                $gemini->where('gemini_analyzed', true)
                    ->where(function (Builder $personas): void {
                        $personas->whereRaw($this->jsonExtract('gemini_analisis_json', 'persona_nueva').' IS NOT NULL')
                            ->orWhereRaw($this->jsonExtract('gemini_analisis_json', 'persona_removida').' IS NOT NULL');
                    });
            })->orWhere(function (Builder $scraperFallback): void {
                // Rama 2 (fallback): Gemini aún no analizó Y scraper detectó posibles_peps.
                $scraperFallback->where('gemini_analyzed', false)
                    ->whereNotNull('posibles_peps')
                    ->where('posibles_peps', '!=', '');
            });
        });
    }

    /**
     * Scope: cambios sin persona detectada.
     *
     * Solo incluye cambios donde Gemini analizó y dictaminó que NO hay
     * persona. El estado del scraper (posibles_peps) ya no importa una
     * vez que Gemini habló: Gemini es la fuente de verdad.
     *
     * Los cambios pending (gemini_analyzed=false) NO entran acá: están
     * en limbo hasta que Gemini analice.
     */
    public function scopeSinPersona(Builder $query): Builder
    {
        return $query->where('gemini_analyzed', true)
            ->whereRaw(
                '('.$this->jsonExtract('gemini_analisis_json', 'persona_nueva').' IS NULL'
                .' AND '.$this->jsonExtract('gemini_analisis_json', 'persona_removida').' IS NULL)'
            );
    }

    /**
     * Scope: filtrar por nivel de riesgo en el análisis Gemini.
     */
    public function scopeConRiesgo(Builder $query, string $riesgo): Builder
    {
        return $query->where('gemini_analyzed', true)
            ->whereRaw($this->jsonExtract('gemini_analisis_json', 'riesgo').' = ?', [$riesgo]);
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
