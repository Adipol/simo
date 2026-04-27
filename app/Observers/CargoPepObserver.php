<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CargoPep;
use Illuminate\Support\Facades\Cache;

/**
 * Observer for CargoPep model — responsible for cache invalidation only.
 *
 * Flushes the 'pre-filtro-terms' cache key whenever a CargoPep row is
 * created, updated, or deleted via Eloquent, ensuring PreFiltroService
 * reloads fresh terms from the database on next use.
 *
 * @note CargosPepBoliviaSeeder uses DB::table('cargos_pep')->updateOrInsert()
 *       which bypasses Eloquent events, so this observer will NOT fire on
 *       seeder runs. The 5-min Cache TTL in PreFiltroService::CACHE_TTL is
 *       the safety net for seeder-triggered changes.
 *
 * @see App\Services\Gemini\PreFiltroService::flushCache()
 * @see database/seeders/CargosPepBoliviaSeeder.php
 */
class CargoPepObserver
{
    public function created(CargoPep $cargoPep): void
    {
        Cache::forget('pre-filtro-terms');
    }

    public function updated(CargoPep $cargoPep): void
    {
        Cache::forget('pre-filtro-terms');
    }

    public function deleted(CargoPep $cargoPep): void
    {
        Cache::forget('pre-filtro-terms');
    }
}
