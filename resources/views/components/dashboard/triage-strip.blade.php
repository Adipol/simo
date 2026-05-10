@props(['triage'])

@php
    /** @var \App\Services\Dashboard\DTOs\TriageStripDTO $triage */
@endphp

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

    {{--
        URLs deben incluir EXACTAMENTE los mismos filtros que el KPI cuenta:
        - filtroRevisado=0 → bandeja muestra solo pendientes (KPI cuenta revisado=false)
        - filtroConPersona=si es el default de la bandeja, no necesita explicitarse
        - filtroLeido=0 → unread (PHP cast: '0' → false; 'no' o cualquier string es truthy)
    --}}

    <x-dashboard.triage-card
        label="Alto riesgo"
        :count="$triage->pendientes_alto"
        :sparkline="$triage->sparkline_alto"
        color="rose"
        :href="route('pep.cambios') . '?filtroRiesgo=alto&filtroRevisado=0'"
    />

    <x-dashboard.triage-card
        label="Riesgo medio"
        :count="$triage->pendientes_medio"
        :sparkline="$triage->sparkline_medio"
        color="amber"
        :href="route('pep.cambios') . '?filtroRiesgo=medio&filtroRevisado=0'"
    />

    <x-dashboard.triage-card
        label="Sin leer"
        :count="$triage->sin_leer"
        :sparkline="$triage->sparkline_sin_leer"
        color="amber"
        :href="route('scraper.resultados') . '?filtroLeido=0'"
    />

    <x-dashboard.triage-card
        label="Bajo riesgo"
        :count="$triage->pendientes_bajo"
        :sparkline="$triage->sparkline_bajo"
        color="zinc"
        :href="route('pep.cambios') . '?filtroRiesgo=bajo&filtroRevisado=0'"
    />

</div>
