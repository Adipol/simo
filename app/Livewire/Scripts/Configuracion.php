<?php

namespace App\Livewire\Scripts;

use App\Models\ConfigScript;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Configuracion de Scripts'])]
class Configuracion extends Component
{
    // Scraper
    public bool $scraperHabilitado = true;

    public int $scraperIntervalo = 60;

    public string $scraperHoraInicio = '06:00';

    public string $scraperHoraFin = '23:00';

    public array $scraperDias = [1, 2, 3, 4, 5, 6, 7];

    public int $scraperTimeout = 120;

    public string $scraperNotas = '';

    // PEP Monitor
    public bool $pepHabilitado = true;

    public int $pepIntervalo = 300;

    public string $pepHoraInicio = '';

    public string $pepHoraFin = '';

    public array $pepDias = [1, 2, 3, 4, 5];

    public int $pepTimeout = 60;

    public string $pepNotas = '';

    public string $mensaje = '';

    public string $tipoMensaje = '';

    public function mount(): void
    {
        $this->cargarConfig('scraper');
        $this->cargarConfig('pep_monitor');
    }

    private function cargarConfig(string $script): void
    {
        $cfg = ConfigScript::para($script);
        $prefix = $script === 'scraper' ? 'scraper' : 'pep';

        $this->{$prefix.'Habilitado'} = (bool) $cfg->habilitado;
        $this->{$prefix.'Intervalo'} = (int) $cfg->intervalo_minutos;
        $this->{$prefix.'HoraInicio'} = $cfg->hora_inicio ? substr($cfg->hora_inicio, 0, 5) : '';
        $this->{$prefix.'HoraFin'} = $cfg->hora_fin ? substr($cfg->hora_fin, 0, 5) : '';
        $this->{$prefix.'Dias'} = $cfg->diasArray();
        $this->{$prefix.'Timeout'} = (int) $cfg->timeout_minutos;
        $this->{$prefix.'Notas'} = $cfg->notas ?? '';
    }

    public function guardar(): void
    {
        $this->validate([
            'scraperIntervalo' => 'required|integer|min:5|max:1440',
            'scraperTimeout' => 'required|integer|min:5|max:480',
            'pepIntervalo' => 'required|integer|min:5|max:1440',
            'pepTimeout' => 'required|integer|min:5|max:480',
        ], [
            'scraperIntervalo.min' => 'El intervalo minimo es 5 minutos.',
            'scraperIntervalo.max' => 'El intervalo maximo es 1440 minutos (24h).',
            'pepIntervalo.min' => 'El intervalo minimo es 5 minutos.',
            'pepIntervalo.max' => 'El intervalo maximo es 1440 minutos (24h).',
        ]);

        $this->guardarScript('scraper');
        $this->guardarScript('pep_monitor');

        $this->mensaje = 'Configuracion guardada. Los cambios se aplicaran en el proximo ciclo del runner.';
        $this->tipoMensaje = 'success';
    }

    private function guardarScript(string $script): void
    {
        $prefix = $script === 'scraper' ? 'scraper' : 'pep';
        $dias = $this->{$prefix.'Dias'};
        sort($dias);

        ConfigScript::updateOrCreate(['script' => $script], [
            'habilitado' => $this->{$prefix.'Habilitado'},
            'intervalo_minutos' => $this->{$prefix.'Intervalo'},
            'hora_inicio' => $this->{$prefix.'HoraInicio'} ?: null,
            'hora_fin' => $this->{$prefix.'HoraFin'} ?: null,
            'dias_semana' => implode(',', array_filter($dias)),
            'timeout_minutos' => $this->{$prefix.'Timeout'},
            'notas' => $this->{$prefix.'Notas'} ?: null,
        ]);
    }

    public function render()
    {
        return view('livewire.scripts.configuracion');
    }
}
