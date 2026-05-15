<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CargoPep;
use App\Models\Cambio;
use App\Models\ResultadoScraping;
use App\Observers\CargoPepObserver;
use App\Observers\CambioObserver;
use App\Observers\ResultadoScrapingObserver;
use App\Services\Contracts\NegativeExamplesProvider;
use App\Services\DescartadosAnalisisService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\PepCatalogService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PepCatalogService::class);

        $this->app->singleton(NegativeExamplesProvider::class, DescartadosAnalisisService::class);

        $this->app->singleton(GeminiPromptBuilder::class, function ($app) {
            return new GeminiPromptBuilder(
                catalog: $app->make(PepCatalogService::class),
                negativeExamplesService: $app->make(NegativeExamplesProvider::class),
                negativeExamplesLimit: (int) config('services.gemini.negative_examples_limit', 5),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResultadoScraping::observe(ResultadoScrapingObserver::class);
        Cambio::observe(CambioObserver::class);
        CargoPep::observe(CargoPepObserver::class);
    }
}
