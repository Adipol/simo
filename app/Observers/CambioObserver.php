<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AnalizarCambioConPro;
use App\Models\Cambio;

class CambioObserver
{
    public function created(Cambio $cambio): void
    {
        if (! config('services.gemini.enabled')) {
            return;
        }

        AnalizarCambioConPro::dispatch()
            ->delay(now()->addSeconds(config('services.gemini.pro_delay', 30)));
    }
}
