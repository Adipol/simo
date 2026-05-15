<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use Illuminate\Support\Collection;

/**
 * Seam for fetching high-confidence operator-rejected articles
 * to use as negative examples in Gemini prompts.
 *
 * Implemented by DescartadosAnalisisService.
 * Extracted as an interface so GeminiPromptBuilder depends on an abstraction,
 * not a concrete final class, enabling pure-unit testing without Mockery hacks.
 */
interface NegativeExamplesProvider
{
    /**
     * Return up to $limit high-confidence descartados as negative examples.
     *
     * @return Collection<int, \App\Models\ResultadoScraping>
     */
    public function getNegativeExamples(int $limit = 10): Collection;
}
