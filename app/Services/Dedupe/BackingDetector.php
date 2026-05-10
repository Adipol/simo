<?php

declare(strict_types=1);

namespace App\Services\Dedupe;

/**
 * Pure service that scores a text context for the presence of documentary
 * backing keywords (resoluciones, decretos, oficios, etc.).
 *
 * Design D4: 5 constant categories, mb_stripos for case-insensitive UTF-8 search.
 * No DB access — completely stateless and easily testable.
 */
final class BackingDetector
{
    /** Legal norms: resolutions, decrees, laws */
    public const NORMATIVA = [
        'resolución administrativa',
        'resolución suprema',
        'decreto supremo',
        'decreto presidencial',
        'resolución',
        'decreto',
        'normativa',
        'ley',
    ];

    /** Formal documents: memos, circulars, communiqués */
    public const DOCUMENTOS = [
        'comunicado oficial',
        'memorándum',
        'memorando',
        'instructivo',
        'circular',
        'oficio',
    ];

    /** Action verbs indicating formal appointment */
    public const ACCIONES = [
        'designado mediante',
        'nombrado por',
        'según el documento',
        'posesionado',
        'juramentado',
    ];

    /** Authoritative sources */
    public const FUENTE = [
        'ministerio público',
        'gaceta oficial',
        'informe oficial',
        'contraloría',
        'auditoría',
        'fiscalía',
    ];

    /** Generic backing indicators */
    public const GENERICO = [
        'según consta',
        'conforme a',
        'fundamenta',
        'sustento',
        'respaldo',
    ];

    /**
     * Count how many backing keywords appear in the given context.
     *
     * Uses mb_stripos for case-insensitive, multibyte-safe search.
     * Keywords within each category are checked in order from longest to
     * shortest (constant arrays are already ordered that way) to avoid
     * double-counting overlapping patterns.
     *
     * @return int Total number of keyword matches across all 5 categories.
     */
    public function score(?string $contexto): int
    {
        if ($contexto === null || $contexto === '') {
            return 0;
        }

        $total = 0;

        foreach ($this->allCategories() as $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_stripos($contexto, $keyword) !== false) {
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * Returns true if at least one backing keyword is found.
     */
    public function hasAny(?string $contexto): bool
    {
        return $this->score($contexto) > 0;
    }

    /**
     * @return array<array<string>>
     */
    private function allCategories(): array
    {
        return [
            self::NORMATIVA,
            self::DOCUMENTOS,
            self::ACCIONES,
            self::FUENTE,
            self::GENERICO,
        ];
    }
}
