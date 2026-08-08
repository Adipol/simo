<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\ResultadoScraping;
use App\Services\Dedupe\DedupeConfigurationService;
use Illuminate\Database\Eloquent\Builder;

final class GeminiFlashEligibilityService
{
    public function __construct(
        private readonly DedupeConfigurationService $dedupeConfiguration,
    ) {}

    /** @return Builder<ResultadoScraping> */
    public function query(): Builder
    {
        return ResultadoScraping::query()
            ->where('gemini_analyzed', false)
            ->whereNull('secundario_de')
            ->when($this->dedupeConfiguration->isEnabled(), static function (Builder $query): void {
                $query->whereNotNull('dedupe_processed_at');
            });
    }
}
