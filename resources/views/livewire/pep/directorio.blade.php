<div class="space-y-6">
    <div>
        <h2 class="text-xl font-semibold text-zinc-800">Directorio PEP</h2>
        <p class="text-sm text-zinc-500">Propuestas del directorio · revisión humana separada de Cambios PEP.</p>
        <p class="text-sm text-zinc-500">La fecha de detección no será presentada como fecha de salida o nombramiento. Confirmar no acredita permanencia legal en el cargo.</p>
    </div>

    <div class="simo-card p-4 flex gap-4">
        <label class="text-sm">Estado
            <select wire:model.live="state" class="simo-select">
                <option value="">Todos</option>
                <option value="pending">Pendiente</option>
                <option value="confirmed">Confirmado</option>
                <option value="discarded">Descartado</option>
            </select>
            @error('state') <span class="text-red-700">{{ $message }}</span> @enderror
        </label>
        <label class="text-sm">Fuente
            <select wire:model.live="source" class="simo-select">
                <option value="">Todas</option>
                @foreach($this->sources as $directorySource)
                    <option wire:key="source-{{ $directorySource->id }}" value="{{ $directorySource->id }}">{{ $this->institution($directorySource) }}</option>
                @endforeach
            </select>
            @error('source') <span class="text-red-700">{{ $message }}</span> @enderror
        </label>
    </div>

    <div class="simo-card overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-zinc-50 text-zinc-500"><tr>
                <th class="p-3">Estado</th><th class="p-3">Institución / unidad / cargo</th>
                <th class="p-3">Persona anterior</th><th class="p-3">Persona nueva</th><th class="p-3">Fecha de detección</th><th class="p-3">Evidencia</th>
            </tr></thead>
            <tbody class="divide-y divide-zinc-100">
            @forelse($this->proposals as $event)
                <tr wire:key="proposal-{{ $event->id }}">
                    <td class="p-3"><span class="simo-badge">{{ $this->stateLabel($event->review_state) }}</span></td>
                    <td class="p-3">{{ $this->institution($event->source) }}<br>{{ $event->payload['unit'] ?? 'Unidad no indicada' }} · {{ $event->payload['role'] ?? 'Cargo no indicado' }}</td>
                    <td class="p-3">
                        @forelse($event->payload['before_group'] ?? (isset($event->payload['before']) ? [$event->payload['before']] : []) as $index => $holder)
                            <div wire:key="before-{{ $event->id }}-{{ $index }}">{{ $holder['person'] ?? 'Sin nombre documentado' }} · {{ $holder['condition'] ?? '' }}</div>
                        @empty <span>Sin antecedente documentado</span> @endforelse
                    </td>
                    <td class="p-3">
                        @forelse($event->payload['after_group'] ?? (isset($event->payload['after']) ? [$event->payload['after']] : []) as $index => $holder)
                            <div wire:key="after-{{ $event->id }}-{{ $index }}">{{ $holder['person'] ?? 'Sin nombre documentado' }} · {{ $holder['condition'] ?? '' }}</div>
                        @empty <span>Sucesor desconocido</span> @endforelse
                    </td>
                    <td class="p-3">{{ $event->payload['detected_at'] ?? $event->currentObservation?->observed_at ?? 'Desconocida' }}</td>
                    <td class="p-3"><button type="button" wire:click="selectProposal({{ $event->id }})" class="simo-btn">Ver detalle</button></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-zinc-500">Sin propuestas para estos filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="p-4 flex items-center gap-3" aria-label="Paginación de propuestas">
            <button type="button" class="simo-btn" wire:click="previousPage" @disabled($this->proposals->onFirstPage())>Anterior</button>
            <span>Página {{ $this->proposals->currentPage() }} de {{ $this->proposals->lastPage() }}</span>
            <button type="button" class="simo-btn" wire:click="nextPage" @disabled(! $this->proposals->hasMorePages())>Siguiente</button>
        </div>
    </div>

    @if($this->proposal)
        <section class="simo-card p-5 space-y-4" aria-label="Detalle de propuesta">
            <h3 class="font-semibold">{{ $this->institution($this->proposal->source) }} · {{ $this->proposal->payload['unit'] ?? '' }} · {{ $this->proposal->payload['role'] ?? '' }}</h3>
            <p class="text-sm">{{ $this->proposal->payload['kind'] ?? 'Propuesta' }} · Versión mostrada: {{ $displayedVersion }}</p>
            <p class="text-sm">Fecha efectiva del cambio: {{ $this->proposal->payload['effective_date'] ?? 'desconocida' }}</p>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div><h4 class="font-medium">Personas que dejan de figurar</h4>
                    @forelse($this->proposal->payload['outgoing'] ?? [] as $index => $holder)
                        <p wire:key="outgoing-{{ $selectedProposal }}-{{ $index }}">{{ $holder['person'] ?? '' }} · {{ $holder['condition'] ?? '' }}</p>
                    @empty <p>Sin salida documentada</p> @endforelse
                </div>
                <div><h4 class="font-medium">Personas que aparecen</h4>
                    @forelse($this->proposal->payload['incoming'] ?? [] as $index => $holder)
                        <p wire:key="incoming-{{ $selectedProposal }}-{{ $index }}">{{ $holder['person'] ?? '' }} · {{ $holder['condition'] ?? '' }}</p>
                    @empty <p>Sin ingreso documentado</p> @endforelse
                </div>
                <div><h4 class="font-medium">Evidencia anterior</h4><p class="text-xs">{{ $this->proposal->payload['previous_observed_at'] ?? $this->proposal->previousObservation?->observed_at ?? 'Fecha desconocida' }}</p><pre class="whitespace-pre-wrap break-words text-xs">{{ $this->proposal->payload['previous_evidence'] ?? $this->proposal->previousObservation?->payload['evidence'] ?? 'No disponible' }}</pre></div>
                <div><h4 class="font-medium">Evidencia nueva</h4><p class="text-xs">{{ $this->proposal->payload['detected_at'] ?? $this->proposal->currentObservation?->observed_at ?? 'Fecha desconocida' }}</p><pre class="whitespace-pre-wrap break-words text-xs">{{ $this->proposal->payload['current_evidence'] ?? $this->proposal->currentObservation?->payload['evidence'] ?? 'No disponible' }}</pre></div>
            </div>
            @foreach($this->proposal->payload['reasons'] ?? [] as $index => $reasonText)
                <p wire:key="proposal-reason-{{ $selectedProposal }}-{{ $index }}">{{ $reasonText }}</p>
            @endforeach

            @if($conflict)
                <p role="alert" class="text-red-700">Hay una revisión más reciente. Recargue la propuesta y decida de nuevo explícitamente.</p>
            @elseif($decisionSaved)
                <p role="status" class="text-emerald-700">Decisión registrada. Recargue para una nueva revisión.</p>
            @endif
            <button type="button" wire:click="reloadProposal" class="simo-btn">Recargar propuesta</button>
            @if($this->canReview)
                <label class="block text-sm">Motivo de la decisión
                    <textarea wire:model="reason" maxlength="10000" class="simo-input w-full" rows="3"></textarea>
                </label>
                @error('reason') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
                @error('state') <p role="alert" class="text-red-700">{{ $message }}</p> @enderror
                <div class="flex gap-3">
                    <button type="button" wire:click="decide('confirmed')" wire:loading.attr="disabled" @disabled($conflict || $decisionSaved) class="simo-btn">Confirmar</button>
                    <button type="button" wire:click="decide('discarded')" wire:loading.attr="disabled" @disabled($conflict || $decisionSaved) class="simo-btn">Descartar</button>
                    <button type="button" wire:click="decide('pending')" wire:loading.attr="disabled" @disabled($conflict || $decisionSaved) class="simo-btn">Mantener pendiente</button>
                </div>
            @else
                <p class="text-sm text-zinc-500">Solo lectura: la revisión formal requiere gestionar fuentes.</p>
            @endif

            <h4 class="font-semibold">Historial de revisión</h4>
            @forelse($this->proposal->reviews as $review)
                <article wire:key="review-{{ $review->id }}" class="border-l-2 border-zinc-200 pl-3 text-sm">
                    <p>{{ $review->actor_snapshot['name'] ?? 'Sin nombre conservado' }} · {{ $review->actor_identity }} · versión {{ $review->version }} · {{ $review->decided_at }}</p>
                    <p>{{ $this->stateLabel($review->prior_state) }} → {{ $this->stateLabel($review->new_state) }}: {{ $review->reason }}</p>
                    @if($review->user)<p class="text-xs text-zinc-500">Usuario vinculado actualmente: {{ $review->user->name }}</p>@endif
                </article>
            @empty <p class="text-sm text-zinc-500">Sin decisiones formales.</p> @endforelse
        </section>

        <section class="simo-card p-5 space-y-3" aria-label="Historia del cargo">
            <h3 class="font-semibold">Últimos titulares observados</h3>
            <p class="text-sm">Fuente: {{ $this->proposal->source->identity }} · {{ $this->proposal->source->baselineObservation?->observed_at ?? 'Sin referencia calificada' }}</p>
            @forelse($this->holders($this->proposal->source->baselineObservation) as $index => $holder)
                <p wire:key="baseline-{{ $selectedProposal }}-{{ $index }}">{{ $holder['person'] ?? '' }} · {{ $holder['condition'] ?? '' }}</p>
            @empty <p>Sin titular observado para esta unidad/cargo exactos; la ausencia no acredita salida.</p> @endforelse
            <p class="text-xs text-zinc-500">La última referencia calificada no acredita vigencia indefinida. Las capturas rechazadas no establecen salidas.</p>
            <h4 class="font-medium">Observaciones de la fuente · unidad y cargo exactos</h4>
            @foreach($this->history as $observation)
                <article wire:key="history-{{ $observation->id }}" class="border-t border-zinc-100 pt-3 text-sm">
                    <p>{{ $observation->observed_at }} · {{ $observation->disposition === 'rejected' ? 'Rechazada' : 'Observación aceptada (incluye sin cambio)' }} · {{ $observation->disposition }}</p>
                    @foreach($this->holders($observation) as $index => $holder)
                        <p wire:key="history-holder-{{ $observation->id }}-{{ $index }}">{{ $holder['person'] ?? '' }} · {{ $holder['condition'] ?? '' }}</p>
                    @endforeach
                    @foreach($observation->reasons ?? [] as $index => $observationReason)
                        <p wire:key="history-reason-{{ $observation->id }}-{{ $index }}">{{ $observationReason }}</p>
                    @endforeach
                    <pre class="text-xs whitespace-pre-wrap break-words">{{ $observation->payload['evidence'] ?? 'Sin evidencia textual adicional' }}</pre>
                </article>
            @endforeach
            <div class="flex items-center gap-3" aria-label="Paginación del historial">
                <button type="button" class="simo-btn" wire:click="previousPage('historyPage')" @disabled($this->history->onFirstPage())>Anterior</button>
                <span>Página {{ $this->history->currentPage() }} de {{ $this->history->lastPage() }}</span>
                <button type="button" class="simo-btn" wire:click="nextPage('historyPage')" @disabled(! $this->history->hasMorePages())>Siguiente</button>
            </div>
        </section>
    @endif
</div>
