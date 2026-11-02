<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class ManualTitleGateBypass
{
    public function send(int $id): string
    {
        Gate::authorize('gestionar resultados');
        Validator::make(['id' => $id], ['id' => ['required', 'integer', 'min:1', 'exists:resultados_scraping,id']])->validate();

        if (! config('services.gemini.enabled') || ! is_string(config('services.gemini.api_key'))
            || trim(config('services.gemini.api_key')) === '') {
            return 'unavailable';
        }

        // Same article mutex as the worker, non-blocking in the web request.
        $result = Cache::lock(ScraperTitleGate::lockKey($id), 360)->get(function () use ($id): string {
            // Atomic fresh-state comparison: change exactly one marker, never manual,
            // title, Gemini or persona data. A second click cannot dispatch again.
            $changed = ResultadoScraping::whereKey($id)
                ->where('deepseek_title_status', 'unrelated')
                ->where('gemini_analyzed', false)
                ->whereNull('gemini_analyzed_at')
                ->whereNull('deepseek_bypass_at')
                ->update(['deepseek_bypass_at' => now()]);

            if ($changed !== 1) {
                return 'ineligible';
            }

            try {
                Bus::dispatch(new AnalizarScrapingConFlash);
            } catch (\Throwable) {
                // Keep the durable marker: simo:analizar-gemini's existing five-minute
                // scanner can recover this pending article. Rolling back could race
                // with an accepted-but-unacknowledged queue push. No exactly-once claim.
                // Never expose/log raw queue/provider exception content here.
                return 'dispatch_failed';
            }

            return 'scheduled';
        });

        return $result === false ? 'busy' : $result;
    }
}
