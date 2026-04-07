<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AnalizarCambioConPro;
use App\Jobs\AnalizarScrapingConFlash;
use App\Models\Cambio;
use App\Models\ResultadoScraping;
use Illuminate\Console\Command;

class AnalizarGemini extends Command
{
    protected $signature = 'simo:analizar-gemini
                            {--flash-only : Solo procesar resultados de scraping}
                            {--pro-only : Solo procesar cambios}';

    protected $description = 'Despacha jobs Gemini para registros pendientes';

    public function handle(): int
    {
        if (! config('services.gemini.enabled', true)) {
            $this->warn('Gemini está deshabilitado (GEMINI_ENABLED=false).');

            return self::SUCCESS;
        }

        $flashOnly = $this->option('flash-only');
        $proOnly = $this->option('pro-only');

        if (! $proOnly) {
            $flashPending = ResultadoScraping::where('gemini_analyzed', false)->count();
            $this->line("Flash: {$flashPending} pendientes");

            if ($flashPending > 0) {
                AnalizarScrapingConFlash::dispatch()->onQueue('gemini');
            }
        }

        if (! $flashOnly) {
            $proPending = Cambio::where('gemini_analyzed', false)->count();
            $this->line("Pro: {$proPending} pendientes");

            if ($proPending > 0) {
                AnalizarCambioConPro::dispatch()->onQueue('gemini');
            }
        }

        return self::SUCCESS;
    }
}
