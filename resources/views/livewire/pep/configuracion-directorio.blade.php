<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold text-slate-800">Configuración de directorios</h1>
        <p class="text-sm text-slate-600">Elija una fuente, obtenga su página y asocie unidad, cargo y persona. Revise la vista previa antes de confirmar. Confirmar no activa la detección.</p>
        <a href="{{ route('pep.directorio') }}" wire:navigate class="simo-btn">Ver eventos del directorio</a>
    </header>
    <section class="rounded-lg border border-slate-200 bg-white p-4 space-y-3" aria-labelledby="sources-heading">
        <h2 id="sources-heading" class="font-semibold">1. Elegir fuente PEP existente</h2>
        @if($this->canManage)
            <details><summary>Seleccionar una fuente activa para configurar</summary>
                <div class="flex flex-wrap gap-2 mt-3">
                    @forelse($this->availableFuentes as $fuente)
                        <button type="button" wire:key="available-fuente-{{ $fuente->id }}" wire:click="useFuente({{ $fuente->id }})" wire:loading.attr="disabled" class="simo-btn">{{ $fuente->nombre ?? $fuente->url }}</button>
                    @empty<p>No hay fuentes activas disponibles.</p>@endforelse
                </div>
            </details>
        @endif
        <p class="text-sm text-slate-600">Seleccionar registra la configuración si hace falta; no modifica la URL ni activa la detección.</p>
        @forelse($this->sources as $source)
            <button type="button" wire:key="configuration-source-{{ $source->id }}" wire:click="selectSource({{ $source->id }})" class="simo-btn" aria-pressed="{{ $sourceId === $source->id ? 'true' : 'false' }}">{{ $source->fuente?->nombre ?? $source->identity }}</button>
        @empty
            <p>Sin fuentes configurables registradas.</p>
        @endforelse
    </section>
    @if($this->selectedSource)
        <section class="rounded-lg border border-slate-200 bg-white p-4 space-y-3" aria-labelledby="capture-heading">
            <h2 id="capture-heading" class="font-semibold">2. Obtener página — versión mostrada {{ $displayedVersion }}</h2>
            <p class="text-sm break-all">URL de la fuente: {{ $this->selectedSource->fuente?->url }}</p>
            @if(str_starts_with($this->selectedSource->fuente?->url ?? '', 'http://'))
                <p class="text-amber-800">Esta fuente usa HTTP sin cifrado.</p>
            @endif
            @if($this->canManage)
                <button type="button" wire:click="capturePage" wire:loading.attr="disabled" class="simo-btn" @disabled(! $this->canCapture)>Obtener página ahora</button>
                <span wire:loading wire:target="capturePage" role="status">Obteniendo página…</span>
                @unless($this->canCapture)<p>Captura manual no disponible: requiere una fuente activa, habilitación explícita, intérprete configurado y una decisión sin conflicto.</p>@endunless
            @endif
            <p class="text-sm text-slate-600">Sin redirecciones ni reintentos automáticos. Puede seguir usando una captura almacenada.</p>
            @forelse($this->captures as $capture)
                <button type="button" wire:key="configuration-capture-{{ $capture->id }}" wire:click="selectCapture({{ $capture->id }})" class="simo-btn" aria-pressed="{{ $captureId === $capture->id ? 'true' : 'false' }}">Captura #{{ $capture->id }} · {{ $capture->captured_at?->format('c') }}</button>
                <p class="text-xs break-all">SHA256: {{ $capture->content_sha256 }} · Procedencia almacenada: {{ json_encode($capture->provenance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</p>
            @empty
                <p>Sin captura almacenada; generación no disponible.</p>
            @endforelse
            @unless($this->canGenerate)<p>Generación no disponible: compilador o intérprete no configurado. La confirmación de evidencia almacenada sigue disponible.</p>@endunless
            <button type="button" wire:click="reloadSource" class="simo-btn">Recargar fuente y renovar decisión</button>
        </section>
        @if($conflict)<p role="alert" class="rounded border border-amber-400 bg-amber-50 p-3">El estado cambió. Recargue la fuente y tome una nueva decisión; no se reintentará automáticamente.</p>@endif
        @if($notice !== '')<p role="status" class="rounded border border-emerald-300 bg-emerald-50 p-3">{{ $notice }}</p>@endif
        @if($errors->any())
            <ul role="alert" class="text-red-700">@foreach($errors->all() as $error)<li wire:key="configuration-error-{{ $loop->index }}">{{ $error }}</li>@endforeach</ul>
        @endif
        <form wire:submit="publish" class="rounded-lg border border-slate-200 bg-white p-4 space-y-4">
            <h2 class="font-semibold">3. Asociaciones de extracción</h2>
            <p class="text-sm text-slate-600">Selectores CSS acotados (máximo 512 bytes). Columnas desde 0; cantidad opcional de 1 a 32. La unidad admite valor constante o contexto de directorio/sección. No se editan personas extraídas.</p>
            <fieldset @disabled(! $this->canManage || $conflict) class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach(['directory' => 'Selector del directorio', 'record' => 'Selector de cada registro', 'section' => 'Selector de sección (opcional)', 'columns' => 'Cantidad de columnas (opcional)'] as $key => $label)
                        <label wire:key="mapping-{{ $key }}" class="block text-sm">{{ $label }}<input wire:model="{{ $key }}" type="{{ $key === 'columns' ? 'number' : 'text' }}" @if($key === 'columns') min="1" max="32" @else maxlength="512" @endif class="simo-input block w-full"></label>
                    @endforeach
                </div>
                @foreach(['unit' => 'Unidad / sede', 'role' => 'Cargo', 'person' => 'Persona', 'condition' => 'Condición (opcional)'] as $name => $label)
                    <fieldset wire:key="mapping-field-{{ $name }}" class="rounded border border-slate-200 p-3 grid gap-3 sm:grid-cols-3">
                        <legend class="font-medium text-sm">{{ $label }}</legend>
                        <label class="text-sm">Origen<select wire:model="fields.{{ $name }}.mode" class="simo-select block w-full">
                            <option value="selector">Selector</option><option value="column">Índice de columna</option>
                            @if($name === 'unit')<option value="constant">Valor constante</option>@endif
                            @if($name === 'condition')<option value="none">Sin condición</option>@endif
                        </select></label>
                        <label class="text-sm">Selector, índice o valor<input wire:model="fields.{{ $name }}.value" maxlength="512" class="simo-input block w-full"></label>
                        @if($name === 'unit')
                            <label class="text-sm">Contexto del selector<select wire:model="fields.unit.scope" class="simo-select block w-full"><option value="record">Registro</option><option value="directory">Directorio</option><option value="section">Sección</option></select></label>
                        @endif
                    </fieldset>
                @endforeach
                <label class="block text-sm"><input type="checkbox" wire:model="separateAiSuffix"> Separar sufijo a.i. del cargo como condición</label>
                <p class="text-sm text-slate-600">Solo separa el sufijo terminal a.i.; sin marcador, condición no indicada. No se puede combinar con un mapeo separado de condición: corrija la asociación explícitamente.</p>
                <p wire:dirty wire:target="directory,record,section,columns,separateAiSuffix,fields.unit.mode,fields.unit.value,fields.unit.scope,fields.role.mode,fields.role.value,fields.person.mode,fields.person.value,fields.condition.mode,fields.condition.value" class="text-amber-800" role="status">Cambios sin previsualizar: la vista almacenada NO refleja las ediciones.</p>
                @if($dirty)<p class="text-amber-800">Cambios sin previsualizar: la vista almacenada NO refleja las ediciones.</p>@endif
                <label class="block text-sm">Motivo de la publicación o decisión (obligatorio, máximo 10000 caracteres)<textarea wire:model="reason" maxlength="10000" class="simo-input block w-full"></textarea></label>
                <button type="submit" class="simo-btn" wire:loading.attr="disabled" @disabled($captureId === null || ! $this->canGenerate)>Generar / corregir vista previa sobre esta captura</button>
            </fieldset>
        </form>
        <section class="rounded-lg border border-slate-200 bg-white p-4 space-y-3" aria-labelledby="preview-heading">
            <h2 id="preview-heading" class="font-semibold">4. Vista previa almacenada y confirmación explícita</h2>
            @if($this->preview)
                <p>Vista #{{ $previewId }} · Captura #{{ $this->preview->capture_id }} · Validez: {{ $this->preview->validity }} · Calificación: {{ json_encode($this->preview->qualification) }}</p>
                <p>Sin autoridad operativa: una vista candidata, incluso incompleta y no vacía, no prueba que la captura sea completa ni activa la detección. Los rechazos estructurales o personas vacías no se pueden confirmar.</p>
                <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Unidad</th><th>Cargo</th><th>Persona</th><th>Condición</th><th>Evidencia</th></tr></thead><tbody>
                    @foreach($this->preview->wire_payload['positions'] ?? [] as $position)
                        <tr wire:key="preview-position-{{ $previewId }}-{{ $loop->index }}">@foreach(['unit', 'role', 'person', 'condition', 'evidence'] as $field)<td class="border-t p-2">{{ $position[$field] ?? 'No indicada' }}</td>@endforeach</tr>
                    @endforeach
                </tbody></table></div>
                <details><summary>Evidencia y diagnóstico almacenados (sin interpretar HTML)</summary><pre class="whitespace-pre-wrap break-all text-xs">{{ json_encode($this->preview->wire_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre></details>
            @else<p>Sin vista previa almacenada seleccionada.</p>@endif
            @if($approval = $this->selectedSource->approvedDecision)
                <p>Confirmación almacenada #{{ $approval->id }} · {{ $approval->actor_identity }} · {{ $approval->reason }} · {{ $approval->decided_at?->format('c') }}. Detección no activada.</p>
            @else<p>Sin confirmación vigente almacenada.</p>@endif
            @if($this->canManage)
                <button type="button" wire:click="approve" wire:loading.attr="disabled" class="simo-btn" @disabled($conflict || $dirty || $previewId === null)>Confirmar fuente con esta vista almacenada</button>
                <button type="button" wire:click="revoke" wire:loading.attr="disabled" class="simo-btn" @disabled($conflict)>Revocar confirmación</button>
            @else<p>Solo lectura: se requiere gestionar fuentes para publicar, confirmar o revocar.</p>@endif
        </section>
    @endif
</div>
