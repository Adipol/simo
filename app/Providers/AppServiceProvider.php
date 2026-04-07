<?php

namespace App\Providers;

use App\Models\Cambio;
use App\Models\ResultadoScraping;
use App\Observers\CambioObserver;
use App\Observers\ResultadoScrapingObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResultadoScraping::observe(ResultadoScrapingObserver::class);
        Cambio::observe(CambioObserver::class);
    }
}
