<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="estado" class="simo-select">
            <option value="pending">Pendientes</option>
            <option value="confirmed">Confirmadas</option>
            <option value="rejected">Rechazadas</option>
            <option value="superseded">Reemplazadas</option>
            <option value="">Historial completo</option>
        </select>
        <select wire:model.live="fuente" class="simo-select">
            <option value="">Todas las fuentes</option>
            @foreach($fuentes as $item)
                <option value="{{ $item->id }}">{{ $item->nombre ?: $item->organismo }}</option>
            @endforeach
        </select>
    </div>

    @if($reviews->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white py-16 text-center text-sm text-zinc-500">
            No hay revisiones para mostrar.
        </div>
    @else
        <div class="space-y-3">
            @foreach($reviews as $review)
                <article wire:key="authority-removal-review-{{ $review->id }}" class="rounded-xl border border-zinc-200 bg-white p-5 space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold text-zinc-900">{{ $review->fuente?->nombre ?: $review->fuente?->organismo }}</h2>
                            <a href="{{ $review->fuente?->url }}" target="_blank" rel="noopener noreferrer" class="text-xs text-indigo-600 hover:underline">{{ $review->fuente?->url }}</a>
                        </div>
                        <span class="simo-badge bg-zinc-100 text-zinc-700">{{ $review->estado->value }}</span>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Línea base confiable</h3>
                            <ul class="space-y-1 text-sm text-zinc-700">
                                @foreach($review->linea_base_json as $authority)
                                    <li>{{ $authority['persona'] }} <span class="text-zinc-400">— {{ $authority['cargo'] }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                        <div>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Roster observado</h3>
                            <ul class="space-y-1 text-sm text-zinc-700">
                                @foreach($review->candidato_json as $authority)
                                    <li>{{ $authority['persona'] }} <span class="text-zinc-400">— {{ $authority['cargo'] }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <details class="rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600">
                        <summary class="cursor-pointer font-medium">Evidencia técnica</summary>
                        <pre class="mt-2 overflow-x-auto whitespace-pre-wrap">{{ json_encode($review->evidencia_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>

                    @if($review->estado === \App\Enums\AuthorityRemovalReviewStatus::Pending)
                        <div class="flex justify-end gap-2">
                            <button wire:click="confirm({{ $review->id }})" wire:confirm="¿Confirmar esta remoción y promover el nuevo roster?" class="simo-btn bg-emerald-50 border border-emerald-200 text-emerald-700">Confirmar</button>
                            <button wire:click="reject({{ $review->id }})" wire:confirm="¿Rechazar esta observación?" class="simo-btn bg-rose-50 border border-rose-200 text-rose-700">Rechazar</button>
                        </div>
                    @else
                        <p class="text-xs text-zinc-500">Decidido por {{ $review->decididoPor?->name ?? 'sistema' }} · {{ $review->decidido_at?->format('d/m/Y H:i') ?? '—' }}</p>
                    @endif
                </article>
            @endforeach
        </div>
        {{ $reviews->links() }}
    @endif
</div>
