@props(['triage'])

@php
    /** @var \App\Services\Dashboard\DTOs\TriageStripDTO $triage */
@endphp

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

    <x-dashboard.triage-card
        label="Alto riesgo"
        :count="$triage->pendientes_alto"
        :sparkline="$triage->sparkline_alto"
        color="rose"
        :href="route('pep.cambios') . '?filtroRiesgo=alto'"
    />

    <x-dashboard.triage-card
        label="Riesgo medio"
        :count="$triage->pendientes_medio"
        :sparkline="$triage->sparkline_medio"
        color="amber"
        :href="route('pep.cambios') . '?filtroRiesgo=medio'"
    />

    <x-dashboard.triage-card
        label="Sin leer"
        :count="$triage->sin_leer"
        :sparkline="$triage->sparkline_sin_leer"
        color="amber"
        :href="route('scraper.resultados') . '?filtroLeido=no'"
    />

    <x-dashboard.triage-card
        label="Bajo riesgo"
        :count="$triage->pendientes_bajo"
        :sparkline="$triage->sparkline_bajo"
        color="zinc"
        :href="route('pep.cambios') . '?filtroRiesgo=bajo'"
    />

</div>
