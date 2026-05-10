<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AnalizarScrapingConFlash;
use App\Jobs\DedupeArticulosJob;
use App\Models\ResultadoScraping;

class ResultadoScrapingObserver
{
    public function created(ResultadoScraping $resultado): void
    {
        // Dispatch Gemini analysis job
        if (config('services.gemini.enabled')) {
            AnalizarScrapingConFlash::dispatch()
                ->delay(now()->addSeconds(config('services.gemini.flash_delay', 4)));
        }

        // Dispatch deduplication job (feature flag: services.dedupe.enabled)
        if (config('services.dedupe.enabled', true)) {
            DedupeArticulosJob::dispatch($resultado->id);
        }
    }
}
