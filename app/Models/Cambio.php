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
