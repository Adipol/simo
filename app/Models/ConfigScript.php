<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigScript extends Model
{
    protected $table = 'config_scripts';

    public $timestamps = false;

    protected $fillable = [
        'script', 'habilitado', 'intervalo_minutos',
        'hora_inicio', 'hora_fin', 'dias_semana',
        'timeout_minutos', 'notas',
    ];

    protected $casts = [
        'habilitado' => 'boolean',
        'intervalo_minutos' => 'integer',
        'timeout_minutos' => 'integer',
    ];

    /**
     * Obtener config de un script, creando defaults si no existe.
     */
    public static function para(string $script): self
    {
        return static::firstOrCreate(
            ['script' => $script],
            [
                'habilitado' => true,
                'intervalo_minutos' => $script === 'scraper' ? 60 : 300,
                'timeout_minutos' => 120,
                'dias_semana' => '1,2,3,4,5,6,7',
            ]
        );
    }

    /**
     * Array legible de dias activos.
     */
    public function diasArray(): array
    {
        return array_map('intval', explode(',', $this->dias_semana));
    }

    /**
     * Etiqueta del intervalo para mostrar en UI.
     */
    public function intervaloLabel(): string
    {
        $min = $this->intervalo_minutos;
        if ($min < 60) {
            return "{$min} minutos";
        }
        $h = intdiv($min, 60);
        $m = $min % 60;

        return $m > 0 ? "{$h}h {$m}min" : ($h === 1 ? '1 hora' : "{$h} horas");
    }

    // =========================================================================
    // Dedupe helpers (Design D5)
    //
    // PRAGMA: The 'dedupe' row reuses config_scripts to store DedupeArticulosJob
    // configuration without a new table migration. Column semantics are repurposed:
    //   - intervalo_minutos → ventana_dias (look-back window in days, default 7)
    //   - notas             → JSON payload with threshold and metadata
    //   - habilitado        → kill-switch for DedupeArticulosJob dispatch
    // =========================================================================

    /**
     * Get (or create) the dedupe configuration row.
     *
     * Uses para() which calls firstOrCreate — if M4 migration ran, this returns
     * the seeded row. If somehow the row is missing, it creates a safe default.
     */
    public static function dedupe(): self
    {
        return static::para('dedupe');
    }

    /**
     * The pg_trgm similarity threshold for deduplication (0–1 scale).
     * Read from notas JSON key 'threshold'. Default: 0.90.
     */
    public function dedupeThreshold(): float
    {
        $payload = json_decode($this->notas ?? '{}', true) ?? [];

        return (float) ($payload['threshold'] ?? 0.90);
    }

    /**
     * The look-back window in days for deduplication queries.
     * Stored in intervalo_minutos (repurposed as ventana_dias). Default: 7.
     */
    public function ventanaDias(): int
    {
        return (int) ($this->intervalo_minutos ?? 7);
    }
}
