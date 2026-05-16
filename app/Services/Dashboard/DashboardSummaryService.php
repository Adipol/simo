<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Cambio;
use App\Models\ResultadoScraping;
use App\Services\Dashboard\DTOs\BacklogAgeDTO;
use App\Services\Dashboard\DTOs\CambioSummary;
use App\Services\Dashboard\DTOs\DashboardSummaryDTO;
use App\Services\Dashboard\DTOs\HeroCardDTO;
use App\Services\Dashboard\DTOs\PepHighConfidence;
use App\Services\Dashboard\DTOs\RecentDiscoveriesDTO;
use App\Services\Dashboard\DTOs\TriageStripDTO;
use App\Support\PgsqlTimezone;
use Illuminate\Support\Facades\DB;

final class DashboardSummaryService
{
    private const CACHE_KEY = 'dashboard:summary';

    public function __construct(
        private readonly DashboardCacheManager $cache,
    ) {}

    /**
     * Return the full dashboard summary DTO, served from cache when available.
     */
    public function getSnapshot(): DashboardSummaryDTO
    {
        $ttl = (int) config('dashboard.summary_cache_ttl', 60);

        return $this->cache->remember(self::CACHE_KEY, $ttl, function (): DashboardSummaryDTO {
            return new DashboardSummaryDTO(
                hero: $this->heroCard(),
                triage: $this->triageStrip(),
                backlog: $this->backlogAge(),
                discoveries: $this->recentDiscoveries(),
                ultima_actividad_revisada: $this->ultimaActividadRevisada(),
            );
        });
    }

    /**
     * Bust (forget) the summary cache key so the next call re-queries.
     */
    public function bust(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Mark a cambio as reviewed and bust the summary cache so the
     * next snapshot reflects the change immediately.
     */
    public function marcarRevisado(int $cambioId): void
    {
        Cambio::findOrFail($cambioId)->update(['revisado' => true]);

        $this->bust();
    }

    // =========================================================================
    // Private computation methods
    // =========================================================================

    /**
     * Return the single highest-scoring unread cambio with a detected persona,
     * or null when there are no pending cambios to triage.
     *
     * Score = (riesgo_alto_weight if riesgo='alto') + (es_mae_weight if es_mae=true)
     *         + EXTRACT(DAY FROM NOW()-fecha) / aging_divisor
     *
     * The formula is driven by config('dashboard.hero_formula').
     */
    private function heroCard(): ?HeroCardDTO
    {
        $w = config('dashboard.hero_formula');

        $riesgoAltoW = (int) ($w['riesgo_alto_weight'] ?? 3);
        $esMaeW = (int) ($w['es_mae_weight'] ?? 2);
        $agingDiv = max(1, (int) ($w['aging_divisor'] ?? 3));

        $isPgsql = DB::getDriverName() === 'pgsql';

        // Build driver-specific score expression
        if ($isPgsql) {
            $riesgoExpr = "CASE WHEN gemini_analisis_json->>'riesgo' = 'alto' THEN {$riesgoAltoW} ELSE 0 END";
            $esMaeExpr = "CASE WHEN (gemini_analisis_json->>'es_mae')::boolean = true THEN {$esMaeW} ELSE 0 END";
            $fechaTz = PgsqlTimezone::normalize('fecha');
            $agingExpr = "EXTRACT(DAY FROM NOW() - {$fechaTz}) / {$agingDiv}";
        } else {
            // SQLite-compatible: JSON via json_extract, days via JULIANDAY
            $riesgoExpr = "CASE WHEN json_extract(gemini_analisis_json, '$.riesgo') = 'alto' THEN {$riesgoAltoW} ELSE 0 END";
            $esMaeExpr = "CASE WHEN json_extract(gemini_analisis_json, '$.es_mae') = 1 THEN {$esMaeW} ELSE 0 END";
            $agingExpr = "CAST((JULIANDAY('now') - JULIANDAY(fecha)) AS INTEGER) / {$agingDiv}";
        }

        $scoreRaw = "({$riesgoExpr} + {$esMaeExpr} + {$agingExpr})";

        $row = Cambio::query()
            ->with('fuente')
            ->selectRaw("cambios.*, {$scoreRaw} AS score")
            ->where('revisado', false)
            ->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($isPgsql): void {
                // conPersona semantics (mirrors Cambio::scopeConPersona)
                $q->where(function (\Illuminate\Database\Eloquent\Builder $gemini) use ($isPgsql): void {
                    $gemini->where('gemini_analyzed', true)
                        ->where(function (\Illuminate\Database\Eloquent\Builder $p) use ($isPgsql): void {
                            if ($isPgsql) {
                                $p->whereRaw("gemini_analisis_json->>'persona_nueva' IS NOT NULL")
                                    ->orWhereRaw("gemini_analisis_json->>'persona_removida' IS NOT NULL");
                            } else {
                                $p->whereRaw("json_extract(gemini_analisis_json, '$.persona_nueva') IS NOT NULL")
                                    ->orWhereRaw("json_extract(gemini_analisis_json, '$.persona_removida') IS NOT NULL");
                            }
                        });
                })->orWhere(function (\Illuminate\Database\Eloquent\Builder $fallback): void {
                    $fallback->where('gemini_analyzed', false)
                        ->whereNotNull('posibles_peps')
                        ->where('posibles_peps', '!=', '');
                });
            })
            ->orderByRaw('score DESC, fecha DESC, id DESC')
            ->limit(1)
            ->first();

        if ($row === null) {
            return null;
        }

        $fuente = $row->fuente;
        $analisis = is_array($row->gemini_analisis_json) ? $row->gemini_analisis_json : [];

        return new HeroCardDTO(
            id: $row->id,
            fuente_nombre: $fuente?->nombre ?? 'Desconocida',
            riesgo: (string) ($analisis['riesgo'] ?? 'desconocido'),
            es_mae: (bool) ($analisis['es_mae'] ?? false),
            dias_pendiente: (int) abs(now()->diffInDays($row->fecha)),
            score: (float) ($row->score ?? 0.0),
            accion_url: url('/pep/cambios').'?id='.$row->id,
            fecha: new \DateTimeImmutable($row->fecha->toDateTimeString()),
        );
    }

    /**
     * Build counts + 7-day sparklines for the four triage buckets.
     * Each sparkline is an array of exactly 7 ints: oldest → newest (today).
     */
    private function triageStrip(): TriageStripDTO
    {
        $buckets = [
            'alto' => $this->riesgoFilter('alto'),
            'medio' => $this->riesgoFilter('medio'),
            'bajo' => $this->riesgoFilter('bajo'),
        ];

        $counts = [];
        $sparklines = [];

        foreach ($buckets as $name => $applyFilter) {
            // Aplicar conPersona() para alinear con el filtro por defecto de la bandeja
            // (ver bug de "37 vs 2" — el KPI debe contar lo mismo que la pantalla destino).
            $base = Cambio::query()->where('revisado', false)->conPersona();
            $applyFilter($base);

            $counts[$name] = (int) (clone $base)->count();

            $dayRows = (clone $base)
                ->where('fecha', '>=', now()->subDays(7))
                ->selectRaw($this->dateTruncDay('fecha').' AS day, COUNT(*) AS cnt')
                ->groupByRaw($this->dateTruncDay('fecha'))
                ->orderByRaw($this->dateTruncDay('fecha').' ASC')
                ->get()
                ->keyBy('day');

            $sparklines[$name] = $this->buildSparkline($dayRows);
        }

        // sin_leer: alineado con el filtro por defecto de la bandeja Resultados
        // (esconde descartados y archivados — son trabajo ya descartado del usuario).
        $counts['sin_leer'] = (int) ResultadoScraping::where('leido', false)
            ->where('descartado', false)
            ->noArchivado()
            ->count();

        $sinLeerRows = ResultadoScraping::where('leido', false)
            ->where('descartado', false)
            ->noArchivado()
            ->where('fecha_encontrado', '>=', now()->subDays(7))
            ->selectRaw($this->dateTruncDay('fecha_encontrado').' AS day, COUNT(*) AS cnt')
            ->groupByRaw($this->dateTruncDay('fecha_encontrado'))
            ->orderByRaw($this->dateTruncDay('fecha_encontrado').' ASC')
            ->get()
            ->keyBy('day');

        $sparklines['sin_leer'] = $this->buildSparkline($sinLeerRows);

        return new TriageStripDTO(
            pendientes_alto: $counts['alto'],
            pendientes_medio: $counts['medio'],
            pendientes_bajo: $counts['bajo'],
            sin_leer: $counts['sin_leer'],
            sparkline_alto: $sparklines['alto'],
            sparkline_medio: $sparklines['medio'],
            sparkline_bajo: $sparklines['bajo'],
            sparkline_sin_leer: $sparklines['sin_leer'],
        );
    }

    /**
     * Returns a closure that applies a riesgo-level filter on a Cambio query.
     * Uses the right JSON accessor for the current DB driver.
     */
    private function riesgoFilter(string $nivel): \Closure
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        return function (\Illuminate\Database\Eloquent\Builder $q) use ($nivel, $isPgsql): void {
            $q->where('gemini_analyzed', true);
            if ($isPgsql) {
                $q->whereRaw("gemini_analisis_json->>'riesgo' = ?", [$nivel]);
            } else {
                $q->whereRaw("json_extract(gemini_analisis_json, '$.riesgo') = ?", [$nivel]);
            }
        };
    }

    /**
     * Returns a SQL expression that truncates a datetime column to a date string.
     * PostgreSQL: DATE_TRUNC('day', col)::date
     * SQLite: DATE(col)
     */
    private function dateTruncDay(string $col): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "DATE_TRUNC('day', {$col})::date"
            : "DATE({$col})";
    }

    /**
     * Fill missing days to build a 7-element sparkline (oldest → newest).
     *
     * @param  \Illuminate\Support\Collection<string, object{day:string, cnt:int}>  $rows
     * @return array<int>
     */
    private function buildSparkline(\Illuminate\Support\Collection $rows): array
    {
        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $key = now()->subDays($i)->toDateString();
            $sparkline[] = (int) ($rows->get($key)?->cnt ?? 0);
        }

        return $sparkline;
    }

    /**
     * Count unread cambios older than config('dashboard.backlog_aging_days').
     * Also returns the age of the oldest one.
     */
    private function backlogAge(): BacklogAgeDTO
    {
        $threshold = (int) config('dashboard.backlog_aging_days', 3);

        $query = Cambio::query()
            ->where('revisado', false)
            ->where('fecha', '<', now()->subDays($threshold));

        $count = (int) $query->count();

        $oldest = $query->min('fecha');

        $masAntiguo = $oldest !== null
            ? (int) abs(now()->diffInDays($oldest))
            : null;

        return new BacklogAgeDTO(
            pendientes_antiguos: $count,
            dias_threshold: $threshold,
            mas_antiguo_dias: $masAntiguo,
        );
    }

    /**
     * Top PEPs detected in the last 24h (high-confidence) and top risk cambios.
     */
    private function recentDiscoveries(): RecentDiscoveriesDTO
    {
        $minConfianza = (int) round((float) config('dashboard.discovery_min_confidence', 0.8) * 100);
        $since24h = now()->subHours(24);

        // Top 5 high-confidence PEPs from ResultadoScraping
        $pepRows = ResultadoScraping::where('gemini_analyzed', true)
            ->where('gemini_is_pep', true)
            ->where('gemini_confianza', '>=', $minConfianza)
            ->where('fecha_encontrado', '>=', $since24h)
            ->orderByDesc('gemini_confianza')
            ->limit(5)
            ->get();

        $topPeps = $pepRows->map(fn (ResultadoScraping $r) => new PepHighConfidence(
            id: $r->id,
            nombre: (string) ($r->gemini_nombre ?? 'Desconocido'),
            cargo: $r->gemini_cargo,
            pais: $r->pais,
            confianza: (float) ($r->gemini_confianza ?? 0),
            categoria: (string) ($r->gemini_categoria ?? 'PEP'),
            fecha: new \DateTimeImmutable($r->fecha_encontrado->toDateTimeString()),
        ))->values()->all();

        // Top 5 risk cambios from last 24h (alto OR medio)
        $cambioRows = Cambio::with('fuente')
            ->where(function (\Illuminate\Database\Eloquent\Builder $q): void {
                $q->where($this->riesgoFilter('alto'))
                    ->orWhere($this->riesgoFilter('medio'));
            })
            ->where('fecha', '>=', $since24h)
            ->orderByDesc('fecha')
            ->limit(5)
            ->get();

        $topCambios = $cambioRows->map(fn (Cambio $c) => new CambioSummary(
            id: $c->id,
            fuente_nombre: $c->fuente?->nombre ?? 'Desconocida',
            riesgo: (string) ($c->gemini_analisis_json['riesgo'] ?? 'desconocido'),
            lineas_nuevas: (int) ($c->lineas_nuevas ?? 0),
            lineas_quitadas: (int) ($c->lineas_quitadas ?? 0),
            analisis_snippet: null,
            fecha: new \DateTimeImmutable($c->fecha->toDateTimeString()),
        ))->values()->all();

        return new RecentDiscoveriesDTO(
            top_peps: $topPeps,
            top_cambios: $topCambios,
        );
    }

    /**
     * Return the MAX(fecha) among revisado=true cambios.
     * This is the PR1 approximation; PR2 will use a dedicated revisado_at column.
     */
    private function ultimaActividadRevisada(): ?\DateTimeImmutable
    {
        $maxFecha = Cambio::where('revisado', true)->max('fecha');

        if ($maxFecha === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $maxFecha);
    }
}
