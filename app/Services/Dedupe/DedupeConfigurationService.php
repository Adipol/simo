<?php

declare(strict_types=1);

namespace App\Services\Dedupe;

use App\Models\ConfigScript;

final class DedupeConfigurationService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.dedupe.enabled', true)
            && ConfigScript::dedupe()->habilitado;
    }
}
