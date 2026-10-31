<section id="evidence-panel" class="simo-evidence-panel" aria-labelledby="evidence-title"
    @if($seleccionado) wire:key="evidence-group-{{ $seleccionado->id }}" x-data="{ counterpart: '{{ $fuentesEvidencia->skip(1)->first()?->id }}' }" @endif>
    @if($seleccionado)
        <header class="space-y-3">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <span class="simo-badge bg-zinc-100 text-zinc-700">Relación registrada · sin reclasificación</span>
                <button type="button" wire:click="cerrarComparacion" class="simo-btn-ghost">Cerrar comparación · Volver a la tabla</button>
            </div>
            <h2 id="evidence-title" class="text-base font-semibold text-zinc-900">{{ $seleccionado->titulo ?: 'Sin título registrado' }}</h2>
            <p class="text-xs text-zinc-600">{{ $fuentesEvidencia->count() }} registro(s) en este grupo · {{ $fuentesEvidencia->count() > 1 ? 'Fuentes vinculadas' : 'Sin fuentes vinculadas' }}. El primario no acredita autoría ni fuente original.</p>
        </header>

        @if($fuentesEvidencia->count() > 1)
            <label class="simo-evidence-selector" for="evidence-counterpart-{{ $seleccionado->id }}">
                Comparar primario con
                <select id="evidence-counterpart-{{ $seleccionado->id }}" class="simo-select min-w-0" x-model="counterpart" data-testid="counterpart-selector">
                    @foreach($fuentesEvidencia->skip(1) as $fuente)
                        <option value="{{ $fuente->id }}">#{{ $fuente->id }} · {{ $fuente->sitio?->nombre ?? 'Sitio no registrado' }} · {{ $fuente->titulo ?: 'Sin título registrado' }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="simo-evidence-pair" data-testid="source-comparison">
            @foreach($fuentesEvidencia as $fuente)
                <article wire:key="evidence-source-{{ $fuente->id }}" class="simo-evidence-source"
                    @if(!$loop->first) :hidden="counterpart !== '{{ $fuente->id }}'" @if($loop->index > 1) hidden @endif @endif>
                    <h3 class="text-sm font-semibold">#{{ $fuente->id }} · {{ $fuente->sitio?->nombre ?? 'Sitio no registrado' }}</h3>
                    <p class="text-sm leading-relaxed break-words">{{ $fuente->titulo ?: 'Sin título registrado' }}</p>
                    <small class="text-xs text-zinc-600">{{ $loop->first ? 'Primario registrado' : 'Fuente vinculada al primario' }} · Título original conservado</small>
                </article>
            @endforeach
            @if($fuentesEvidencia->count() === 1)
                <div class="simo-evidence-source text-zinc-600 text-sm">Sin contraparte registrada</div>
            @endif
        </div>
        <dl class="simo-evidence-facts" data-testid="comparison-facts">
            <dt>Títulos</dt><dd>
                @foreach($fuentesEvidencia->skip(1) as $fuente)
                    <span @if($loop->index > 0) hidden @endif :hidden="counterpart !== '{{ $fuente->id }}'">{{ $comparacionesTitulos[$fuente->id] }} · comparación literal, no de eventos</span>
                @endforeach
                @if($fuentesEvidencia->count() === 1)Sin contraparte para comparar.@endif
            </dd>
            <dt>Persona / evento</dt><dd>Sin validación entre fuentes. Análisis atribuido a cada artículo en su contexto completo.</dd>
            <dt>País / categoría</dt><dd>{{ $seleccionado->pais ?: 'No registrado' }} · {{ $seleccionado->categoria ?: 'No registrada' }} (selección actual; detalle por fuente abajo).</dd>
            <dt>Fecha de hallazgo</dt><dd>{{ $seleccionado->fecha_encontrado?->format('d/m/Y H:i') ?? 'No registrada' }} · No es la fecha del evento; compatibilidad pendiente.</dd>
        </dl>
        <aside class="simo-evidence-conclusion">
            <h3 class="text-sm font-semibold">Grupo almacenado · evento sin validar</h3>
            <p>Se muestran las relaciones almacenadas, no una validación del mismo evento. El score y el análisis Gemini no prueban una coincidencia entre artículos. No se ejecuta ninguna regla nueva ni se proponen uniones con otros primarios.</p>
        </aside>
        <div class="simo-evidence-contexts">
            <h3 class="text-sm font-semibold">Contexto completo por fuente</h3>
            @foreach($fuentesEvidencia as $fuente)
                <details wire:key="evidence-context-{{ $fuente->id }}" data-testid="source-context">
                    <summary>#{{ $fuente->id }} · {{ $fuente->sitio?->nombre ?? 'Sitio no registrado' }} · Contexto, enlace y análisis</summary>
                    <div class="space-y-3 pt-3 text-xs text-zinc-600">
                        <p class="break-words">{{ $fuente->titulo ?: 'Sin título registrado' }}</p>
                        @if($enlacesEvidencia[$fuente->id])
                            <a href="{{ $enlacesEvidencia[$fuente->id] }}" target="_blank" rel="noopener noreferrer" class="block text-indigo-600 underline break-all">{{ $fuente->url }}</a>
                        @else
                            <p class="break-all">Enlace no disponible · {{ $fuente->url }}</p>
                        @endif
                        <dl class="space-y-2">
                            <div><dt class="font-semibold">Keyword</dt><dd>{{ $fuente->keyword ?: 'No registrada' }}{{ $fuente->found_in_title ? ' · en título' : '' }}</dd></div>
                            <div><dt class="font-semibold">País / categoría</dt><dd>{{ $fuente->pais ?: 'No registrado' }} · {{ $fuente->categoria ?: 'No registrada' }}</dd></div>
                            <div><dt class="font-semibold">Fecha de hallazgo</dt><dd>{{ $fuente->fecha_encontrado?->format('d/m/Y H:i') ?? 'No registrada' }}</dd></div>
                            <div><dt class="font-semibold">Score</dt><dd>{{ $fuente->relevance_score ?? '—' }} · No acredita coincidencia</dd></div>
                            <div><dt class="font-semibold">Contexto registrado</dt><dd class="break-words">{{ $fuente->contexto ?: 'Sin contexto registrado' }}</dd></div>
                        </dl>
                        <h4 class="font-semibold">Análisis Gemini de este artículo</h4>
                        @if(!$fuente->gemini_analyzed)
                            <p>Pendiente de análisis</p>
                        @else
                            @if($fuente->gemini_motivo)<p class="break-words">{{ $fuente->gemini_motivo }}</p>@endif
                            @forelse($fuente->personas as $persona)
                                <div wire:key="evidence-person-{{ $persona->id }}" class="space-y-1">
                                    <p class="font-semibold">{{ $persona->nombre ?: 'Nombre no registrado' }}</p>
                                    <p>{{ $persona->cargo ?: 'Cargo no registrado' }} · {{ $persona->categoria ?: 'Categoría no registrada' }}</p>
                                    <p>{{ $persona->evento ?: 'Evento no registrado por el análisis' }}</p>
                                    @if($persona->motivo)<p class="break-words">{{ $persona->motivo }}</p>@endif
                                </div>
                            @empty
                                <p>Sin personas detectadas</p>
                            @endforelse
                        @endif
                    </div>
                </details>
            @endforeach
        </div>
        <div class="border-t border-zinc-200 pt-3">
            @include('livewire.scraper.partials.result-actions', ['r' => $seleccionado])
        </div>
    @endif
</section>
