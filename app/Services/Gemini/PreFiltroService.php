<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\CargoPep;
use App\Models\ResultadoScraping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PreFiltroService
{
    /** Cache key used to store pre-filtro terms. */
    private const CACHE_KEY = 'pre-filtro-terms';

    /** TTL in seconds (5 minutes). Safety net for seeder runs that bypass Eloquent events. */
    private const CACHE_TTL = 300;

    /**
     * Additional common PEP terms not in cargos_pep table.
     */
    private const EXTRA_TERMS = [
        'ministro', 'viceministro', 'presidente', 'vicepresidente',
        'senador', 'diputado', 'gobernador', 'alcalde', 'fiscal',
        'magistrado', 'embajador', 'cónsul', 'comandante', 'rector',
        'procurador', 'contralor', 'defensor', 'secretario general',
    ];

    public function shouldAnalyzeWithGemini(ResultadoScraping $record): bool
    {
        $texto = mb_strtolower($record->contexto ?? $record->titulo ?? '');

        if ($texto === '') {
            return false;
        }

        foreach ($this->getSearchTerms() as $term) {
            if (mb_strpos($texto, $term) !== false) {
                return true;
            }
        }

        Log::channel('gemini')->debug('Pre-filtro descartó registro', [
            'record_id' => $record->id,
            'titulo' => mb_substr($record->titulo ?? '', 0, 80),
        ]);

        return false;
    }

    /**
     * @return array<string>
     */
    private function getSearchTerms(): array
    {
        /** @var array<string> */
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->loadTerms());
    }

    /**
     * Load and merge PEP terms from DB + EXTRA_TERMS constant.
     *
     * @return array<string>
     */
    private function loadTerms(): array
    {
        $dbTerms = CargoPep::active()
            ->pluck('nombre')
            ->flatMap(function (string $nombre): array {
                $words = preg_split('/[\s,()\/]+/', mb_strtolower($nombre));

                return array_filter($words, fn (string $w): bool => mb_strlen($w) >= 4);
            })
            ->unique()
            ->values()
            ->toArray();

        return array_values(array_unique(
            array_merge($dbTerms, self::EXTRA_TERMS)
        ));
    }

    /**
     * Flush the pre-filtro terms from the cache.
     *
     * Called by CargoPepObserver when CargoPep rows are created, updated,
     * or deleted via Eloquent. The 5-min TTL is the safety net for direct
     * DB operations (e.g., seeder) that bypass Eloquent events.
     */
    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
