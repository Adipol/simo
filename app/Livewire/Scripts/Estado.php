<?php

namespace App\Livewire\Scripts;

use App\Models\LogScript;
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

        return view('livewire.scripts.estado', [
            'scraperUltimo'      => $scraperUltimo,
            'pepUltimo'          => $pepUltimo,
            'scraperEjecutando'  => LogScript::estaEjecutando('scraper'),
            'pepEjecutando'      => LogScript::estaEjecutando('pep_monitor'),
            'logs'               => $logs,
        ]);
    }
}
