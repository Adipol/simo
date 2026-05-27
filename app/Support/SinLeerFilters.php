<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for the "sin_leer" filter conditions applied in
 * DashboardSummaryService::sinLeerBaseQuery() and the bandeja Resultados view.
 *
 * Extracting the 5 conditions into a public constant (CONDITIONS) allows:
 * (a) Contract C to assert the exact shape without executing a Builder,
 * (b) sinLeerBaseQuery() to iterate CONDITIONS declaratively instead of
 *     maintaining a parallel inline chain that can silently diverge.
 *
 * See PRs #5, #11, #13 for the history of drift bugs this class prevents.
 */
final class SinLeerFilters
{
    /**
     * The 5 canonical filter conditions that define an unread ResultadoScraping.
     *
     * Each entry has:
     *   - type:   'where' (column = value) or 'scope' (Eloquent named scope)
     *   - target: column name (for 'where') or scope method name (for 'scope')
     *   - value:  the comparison value (only present for type='where')
     *
     * @var array<int, array{type: string, target: string, value?: mixed}>
     */
    public const CONDITIONS = [
        ['type' => 'where', 'target' => 'leido',           'value' => false],
        ['type' => 'where', 'target' => 'descartado',      'value' => false],
        ['type' => 'scope', 'target' => 'noArchivado'],
        ['type' => 'where', 'target' => 'gemini_analyzed', 'value' => true],
        ['type' => 'scope', 'target' => 'onlyPrimaries'],
    ];

    /**
     * Apply all sin_leer filter conditions to the given query builder.
     *
     * @param  Builder<\App\Models\ResultadoScraping>  $q
     * @return Builder<\App\Models\ResultadoScraping>
     */
    public static function apply(Builder $q): Builder
    {
        foreach (self::CONDITIONS as $c) {
            if ($c['type'] === 'where') {
                $q->where($c['target'], $c['value']);
            } else {
                // type === 'scope': call the named Eloquent scope
                $q->{$c['target']}();
            }
        }

        return $q;
    }
}
