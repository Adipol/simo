<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Models\ConfigScript;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Configuracion de Scripts'])]
class Configuracion extends Component
{
    /** Maps script key → component property prefix. */
    private const SCRIPT_PREFIX_MAP = [
        'scraper'     => 'scraper',
        'pep_monitor' => 'pep',
        'gaceta'      => 'gaceta',
    ];

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

    // Gaceta Oficial
    public bool $gacetaHabilitado = true;

    public int $gacetaIntervalo = 60;

    public string $gacetaHoraInicio = '';

    public string $gacetaHoraFin = '';

    public array $gacetaDias = [1, 2, 3, 4, 5, 6, 7];

    public int $gacetaTimeout = 30;

    public string $gacetaNotas = '';

    public string $mensaje = '';

    public string $tipoMensaje = '';

    public function mount(): void
    {
        $this->cargarConfig('scraper');
        $this->cargarConfig('pep_monitor');
        $this->cargarConfig('gaceta');
    }

    private function cargarConfig(string $script): void
    {
        $cfg = ConfigScript::para($script);
        $prefix = self::SCRIPT_PREFIX_MAP[$script]
            ?? throw new \InvalidArgumentException("Unknown script: {$script}");

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
            // Scraper
            'scraperHabilitado'  => 'boolean',
            'scraperIntervalo'   => 'required|integer|min:5|max:1440',
            'scraperTimeout'     => 'required|integer|min:5|max:480',
            'scraperHoraInicio'  => 'nullable|date_format:H:i',
            'scraperHoraFin'     => 'nullable|date_format:H:i',
            'scraperDias'        => 'array',
            'scraperDias.*'      => 'integer|between:1,7',
            'scraperNotas'       => 'nullable|string|max:1000',
            // PEP Monitor
            'pepHabilitado'      => 'boolean',
            'pepIntervalo'       => 'required|integer|min:5|max:1440',
            'pepTimeout'         => 'required|integer|min:5|max:480',
            'pepHoraInicio'      => 'nullable|date_format:H:i',
            'pepHoraFin'         => 'nullable|date_format:H:i',
            'pepDias'            => 'array',
            'pepDias.*'          => 'integer|between:1,7',
            'pepNotas'           => 'nullable|string|max:1000',
            // Gaceta Oficial
            'gacetaHabilitado'   => 'boolean',
            'gacetaIntervalo'    => 'required|integer|min:5|max:1440',
            'gacetaTimeout'      => 'required|integer|min:5|max:480',
            'gacetaHoraInicio'   => 'nullable|date_format:H:i',
            'gacetaHoraFin'      => 'nullable|date_format:H:i',
            'gacetaDias'         => 'array',
            'gacetaDias.*'       => 'integer|between:1,7',
            'gacetaNotas'        => 'nullable|string|max:1000',
        ], [
            'scraperIntervalo.min'   => 'El intervalo minimo es 5 minutos.',
            'scraperIntervalo.max'   => 'El intervalo maximo es 1440 minutos (24h).',
            'scraperDias.*.between'  => 'Los dias deben ser entre 1 (lunes) y 7 (domingo).',
            'pepIntervalo.min'       => 'El intervalo minimo es 5 minutos.',
            'pepIntervalo.max'       => 'El intervalo maximo es 1440 minutos (24h).',
            'pepDias.*.between'      => 'Los dias deben ser entre 1 (lunes) y 7 (domingo).',
            'gacetaIntervalo.min'    => 'El intervalo minimo es 5 minutos.',
            'gacetaIntervalo.max'    => 'El intervalo maximo es 1440 minutos (24h).',
            'gacetaDias.*.between'   => 'Los dias deben ser entre 1 (lunes) y 7 (domingo).',
        ]);

        $this->guardarScript('scraper');
        $this->guardarScript('pep_monitor');
        $this->guardarScript('gaceta');

        $this->mensaje = 'Configuracion guardada. Los cambios se aplicaran en el proximo ciclo del runner.';
        $this->tipoMensaje = 'success';
    }

    private function guardarScript(string $script): void
    {
        $prefix = self::SCRIPT_PREFIX_MAP[$script]
            ?? throw new \InvalidArgumentException("Unknown script: {$script}");
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

    public function render(): View
    {
        return view('livewire.scripts.configuracion');
    }
}
