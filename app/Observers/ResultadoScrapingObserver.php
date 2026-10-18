<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AnalizarScrapingConFlash;
use App\Jobs\DedupeArticulosJob;
use App\Models\ResultadoScraping;
use App\Services\Dedupe\DedupeConfigurationService;

class ResultadoScrapingObserver
{
    public function created(ResultadoScraping $resultado): void
    {
        if (app(DedupeConfigurationService::class)->isEnabled()) {
            DedupeArticulosJob::dispatch($resultado->id);

            return;
        }

        if (config('services.gemini.enabled')) {
            AnalizarScrapingConFlash::dispatch()
                ->delay(now()->addSeconds(config('services.gemini.flash_delay', 4)));
        }
    }
}
