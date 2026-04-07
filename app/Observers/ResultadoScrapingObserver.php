<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;

class ResultadoScrapingObserver
{
    public function created(ResultadoScraping $resultado): void
    {
        if (! config('services.gemini.enabled')) {
            return;
        }

        AnalizarScrapingConFlash::dispatch()
            ->delay(now()->addSeconds(config('services.gemini.flash_delay', 4)));
    }
}
