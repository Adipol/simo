<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\CargoPep;
use App\Models\ResultadoScraping;
use Illuminate\Support\Facades\Log;

class PreFiltroService
{
    /** @var array<string>|null */
    private static ?array $termsCache = null;

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
        if (self::$termsCache !== null) {
            return self::$termsCache;
        }

        $dbTerms = CargoPep::active()
            ->pluck('nombre')
            ->flatMap(function (string $nombre): array {
                $words = preg_split('/[\s,()\/]+/', mb_strtolower($nombre));

                return array_filter($words, fn (string $w): bool => mb_strlen($w) >= 4);
            })
            ->unique()
            ->values()
            ->toArray();

        self::$termsCache = array_unique(
            array_merge($dbTerms, self::EXTRA_TERMS)
        );

        return self::$termsCache;
    }

    public static function flushCache(): void
    {
        self::$termsCache = null;
    }
}
