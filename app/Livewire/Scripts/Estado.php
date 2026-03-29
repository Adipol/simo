<?php

namespace App\Livewire\Scripts;

use App\Models\ConfigScript;
use App\Models\LogScript;
use App\Models\SitioWeb;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Estado de Scripts'])]
class Estado extends Component
{
    use WithPagination;

    public string $filtroScript = '';
    public string $filtroEstado = '';

    public function render()
    {
        $scraperUltimo  = LogScript::ultimaEjecucion('scraper');
        $pepUltimo      = LogScript::ultimaEjecucion('pep_monitor');

        $q = LogScript::orderBy('inicio', 'desc');
        if ($this->filtroScript) $q->where('script', $this->filtroScript);
        if ($this->filtroEstado) $q->where('estado', $this->filtroEstado);

        $logs = $q->paginate(30);

        $scraperEjecutando = LogScript::estaEjecutando('scraper');
        $scraperConfig     = ConfigScript::where('script', 'scraper')->first();

        // Timestamp Unix del inicio del ciclo actual (para contador JS en tiempo real)
        $scraperInicioTs      = ($scraperEjecutando && $scraperUltimo) ? $scraperUltimo->inicio->timestamp : null;
        $scraperTimeoutSeg    = $scraperConfig ? $scraperConfig->timeout_minutos * 60 : null;

        return view('livewire.scripts.estado', [
            'scraperUltimo'           => $scraperUltimo,
            'pepUltimo'               => $pepUltimo,
            'scraperEjecutando'       => $scraperEjecutando,
            'pepEjecutando'           => LogScript::estaEjecutando('pep_monitor'),
            'scraperInicioTs'         => $scraperInicioTs,
            'scraperTimeoutSeg'       => $scraperTimeoutSeg,
            'logs'                    => $logs,
        ]);
    }
}
