<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CambioFeedStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Cambio extends Model
{
    use HasFactory;

    protected $table = 'cambios';

    public $timestamps = false;

    protected $fillable = [
        'fuente_id', 'fecha', 'hash_anterior', 'hash_nuevo',
        'lineas_quitadas', 'lineas_nuevas', 'diff_texto', 'autoridades_eventos_json',
        'feed_status',
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
        'autoridades_eventos_json' => 'array',
        'feed_status' => CambioFeedStatus::class,
    ];

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class, 'fuente_id');
    }

    public function authorityRemovalReview(): HasOne
    {
        return $this->hasOne(AuthorityRemovalReview::class, 'cambio_confirmado_id');
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

    /**
     * Returns a driver-aware expression for the versioned structured event list.
     */
    private function structuredEventsLength(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "jsonb_array_length(COALESCE(autoridades_eventos_json::jsonb->'events', '[]'::jsonb))",
            'sqlite' => "json_array_length(COALESCE(json_extract(autoridades_eventos_json, '$.events'), '[]'))",
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
     * Scope: failed()-stranded Cambio records.
     *
     * Predicate: gemini_analyzed=true AND gemini_analyzed_at IS NULL.
     * These rows were marked by the old buggy AnalizarCambioConPro::failed() handler
     * which set gemini_analyzed=true without setting gemini_analyzed_at, leaving them
     * permanently excluded from the pending query but never actually processed.
     *
     * With the fixed failed() (log-only), new stranded rows are no longer created.
     * This scope is used only by ProStrandedRecoveryService to reset existing ones.
     *
     * Note: terminal-failed rows (from marcarFallido) set BOTH gemini_analyzed=true
     * AND gemini_analyzed_at=now(), so they do NOT match this predicate.
     */
    public function scopeStranded(Builder $query): Builder
    {
        return $query->where('gemini_analyzed', true)
            ->whereNull('gemini_analyzed_at');
    }

    /**
     * Scope: validated canonical authority events admitted to the primary feed.
     *
     * The JSON predicate mirrors AuthorityEventFeedService so a malformed payload
     * cannot enter the feed even if its persisted destination was set incorrectly.
     */
    public function scopePrimaryFeed(Builder $query): Builder
    {
        return $query
            ->where('feed_status', CambioFeedStatus::Primary)
            ->whereRaw($this->primaryFeedPayloadPredicate());
    }

    /**
     * Scope: uncertain changes awaiting human review.
     */
    public function scopeReviewFeed(Builder $query): Builder
    {
        return $query->where('feed_status', CambioFeedStatus::Review);
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
                // Rama 2 (fallback): Gemini aún no analizó y el scraper detectó
                // una persona, ya sea por heurística de texto o por autoridad estructurada.
                $scraperFallback->where('gemini_analyzed', false)
                    ->where(function (Builder $evidence): void {
                        $evidence->whereNotNull('posibles_peps')
                            ->where('posibles_peps', '!=', '')
                            ->orWhereRaw($this->structuredEventsLength().' > 0');
                    });
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

    /**
     * Validate every JSON event in SQL so pagination and aggregate counts share
     * exactly the same admission boundary on PostgreSQL and SQLite.
     */
    private function primaryFeedPayloadPredicate(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => <<<'SQL'
                jsonb_typeof(autoridades_eventos_json::jsonb) = 'object'
                AND jsonb_typeof(autoridades_eventos_json::jsonb->'version') = 'number'
                AND autoridades_eventos_json::jsonb->>'version' = '1'
                AND jsonb_typeof(autoridades_eventos_json::jsonb->'events') = 'array'
                AND jsonb_array_length(CASE WHEN jsonb_typeof(autoridades_eventos_json::jsonb->'events') = 'array' THEN autoridades_eventos_json::jsonb->'events' ELSE '[]'::jsonb END) > 0
                AND NOT EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements(CASE WHEN jsonb_typeof(autoridades_eventos_json::jsonb->'events') = 'array' THEN autoridades_eventos_json::jsonb->'events' ELSE '[]'::jsonb END) AS authority_event(value)
                    WHERE (
                        (
                            authority_event.value->>'type' = 'designacion'
                            AND authority_event.value->'old' = 'null'::jsonb
                            AND jsonb_typeof(authority_event.value->'new') = 'object'
                            AND jsonb_typeof(authority_event.value->'new'->'cargo') = 'string'
                            AND btrim(authority_event.value->'new'->>'cargo', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                            AND jsonb_typeof(authority_event.value->'new'->'persona') = 'string'
                            AND btrim(authority_event.value->'new'->>'persona', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                        ) OR (
                            authority_event.value->>'type' = 'remocion'
                            AND jsonb_typeof(authority_event.value->'old') = 'object'
                            AND jsonb_typeof(authority_event.value->'old'->'cargo') = 'string'
                            AND btrim(authority_event.value->'old'->>'cargo', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                            AND jsonb_typeof(authority_event.value->'old'->'persona') = 'string'
                            AND btrim(authority_event.value->'old'->>'persona', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                            AND authority_event.value->'new' = 'null'::jsonb
                        ) OR (
                            authority_event.value->>'type' IN ('reemplazo', 'cambio_cargo')
                            AND jsonb_typeof(authority_event.value->'old') = 'object'
                            AND jsonb_typeof(authority_event.value->'old'->'cargo') = 'string'
                            AND btrim(authority_event.value->'old'->>'cargo', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                            AND jsonb_typeof(authority_event.value->'old'->'persona') = 'string'
                            AND btrim(authority_event.value->'old'->>'persona', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                            AND jsonb_typeof(authority_event.value->'new') = 'object'
                            AND jsonb_typeof(authority_event.value->'new'->'cargo') = 'string'
                            AND btrim(authority_event.value->'new'->>'cargo', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                            AND jsonb_typeof(authority_event.value->'new'->'persona') = 'string'
                            AND btrim(authority_event.value->'new'->>'persona', U&'\0009\000A\000B\000C\000D\0020\0085\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000') <> ''
                        )
                    ) IS NOT TRUE
                )
                SQL,
            'sqlite' => $this->sqlitePrimaryFeedPayloadPredicate(),
            default => throw new \RuntimeException('Unsupported DB driver: '.DB::getDriverName()),
        };
    }

    private function sqlitePrimaryFeedPayloadPredicate(): string
    {
        $payload = "CASE WHEN json_valid(autoridades_eventos_json) THEN autoridades_eventos_json ELSE '{}' END";
        $event = "CASE WHEN authority_event.type = 'object' THEN authority_event.value ELSE '{}' END";

        return <<<SQL
            json_type({$payload}, '$') = 'object'
            AND json_type({$payload}, '$.version') = 'integer'
            AND json_extract({$payload}, '$.version') = 1
            AND json_type({$payload}, '$.events') = 'array'
            AND json_array_length({$payload}, '$.events') > 0
            AND NOT EXISTS (
                SELECT 1
                FROM json_each({$payload}, '$.events') AS authority_event
                WHERE COALESCE((
                    authority_event.type = 'object'
                    AND (
                        (
                            json_extract({$event}, '$.type') = 'designacion'
                            AND json_type({$event}, '$.old') = 'null'
                            AND json_type({$event}, '$.new') = 'object'
                            AND json_type({$event}, '$.new.cargo') = 'text'
                            AND trim(json_extract({$event}, '$.new.cargo'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                            AND json_type({$event}, '$.new.persona') = 'text'
                            AND trim(json_extract({$event}, '$.new.persona'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                        ) OR (
                            json_extract({$event}, '$.type') = 'remocion'
                            AND json_type({$event}, '$.old') = 'object'
                            AND json_type({$event}, '$.old.cargo') = 'text'
                            AND trim(json_extract({$event}, '$.old.cargo'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                            AND json_type({$event}, '$.old.persona') = 'text'
                            AND trim(json_extract({$event}, '$.old.persona'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                            AND json_type({$event}, '$.new') = 'null'
                        ) OR (
                            json_extract({$event}, '$.type') IN ('reemplazo', 'cambio_cargo')
                            AND json_type({$event}, '$.old') = 'object'
                            AND json_type({$event}, '$.old.cargo') = 'text'
                            AND trim(json_extract({$event}, '$.old.cargo'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                            AND json_type({$event}, '$.old.persona') = 'text'
                            AND trim(json_extract({$event}, '$.old.persona'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                            AND json_type({$event}, '$.new') = 'object'
                            AND json_type({$event}, '$.new.cargo') = 'text'
                            AND trim(json_extract({$event}, '$.new.cargo'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                            AND json_type({$event}, '$.new.persona') = 'text'
                            AND trim(json_extract({$event}, '$.new.persona'), char(9, 10, 11, 12, 13, 32, 133, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8232, 8233, 8239, 8287, 12288)) <> ''
                        )
                    )
                ), 0) = 0
            )
            SQL;
    }
}
