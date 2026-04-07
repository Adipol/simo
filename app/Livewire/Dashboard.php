<?php

namespace App\Livewire;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\LogScript;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    public function render()
    {
        $scraperLog = LogScript::ultimaEjecucion('scraper');
        $pepLog = LogScript::ultimaEjecucion('pep_monitor');

        return view('livewire.dashboard', [
            'totalResultados' => ResultadoScraping::count(),
            'resultadosHoy' => ResultadoScraping::whereDate('fecha_encontrado', today())->count(),
            'resultadosHoyPorCat' => ResultadoScraping::whereDate('fecha_encontrado', today())
                ->selectRaw('categoria, COUNT(*) as total')
                ->groupBy('categoria')
                ->orderByRaw("CASE WHEN categoria = 'PEP' THEN 1 WHEN categoria = 'OPI' THEN 2 ELSE 3 END, categoria")
                ->pluck('total', 'categoria'),
            'resultadosSinLeer' => ResultadoScraping::where('leido', false)->count(),
            'totalFuentes' => Fuente::where('activo', true)->count(),
            'cambiosSinRevisar' => Cambio::where('revisado', false)->count(),
            'totalSitios' => SitioWeb::where('activo', true)->count(),
            'ultimosResultados' => ResultadoScraping::with('sitio')
                ->orderBy('fecha_encontrado', 'desc')
                ->limit(5)
                ->get(),
            'ultimosCambios' => Cambio::with('fuente')
                ->orderBy('fecha', 'desc')
                ->limit(5)
                ->get(),
            'scraperEjecutando' => LogScript::estaEjecutando('scraper'),
            'pepEjecutando' => LogScript::estaEjecutando('pep_monitor'),
            'scraperLog' => $scraperLog,
            'pepLog' => $pepLog,
        ]);
    }
}
