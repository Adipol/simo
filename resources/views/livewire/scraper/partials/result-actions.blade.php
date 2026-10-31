<div class="flex items-center gap-1 flex-wrap">
    @if($r->descartado)
        <button wire:click="restaurar({{ $r->id }})"
            class="simo-btn bg-indigo-50 text-indigo-600 hover:bg-indigo-100">
            Restaurar
        </button>
    @else
        @if(!$r->leido)
            <button wire:click="marcarLeido({{ $r->id }})"
                class="simo-btn-ghost text-gray-400">
                Leido
            </button>
        @endif
        <button wire:click="marcarRelevante({{ $r->id }}, true)"
            class="simo-btn {{ $r->relevante ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-50 text-gray-500 hover:bg-emerald-50 hover:text-emerald-600' }}">
            Relevante
        </button>
        <button wire:click="descartar({{ $r->id }})"
            wire:confirm="Descartar este articulo? No aparecera en la lista principal pero se puede recuperar."
            class="simo-btn-danger" title="Descartar">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        {{-- Archivar / Desarchivar --}}
        @can('gestionar resultados')
            @if($r->archivado_at)
                <button wire:click="desarchivar({{ $r->id }})"
                    class="simo-btn bg-sky-50 text-sky-600 hover:bg-sky-100">
                    Desarchivar
                </button>
            @else
                <button wire:click="archivar({{ $r->id }})"
                    class="simo-btn bg-gray-50 text-gray-500 hover:bg-sky-50 hover:text-sky-600">
                    Archivar
                </button>
            @endif
        @endcan
        @if($r->gemini_analyzed)
            <button wire:click="$set('verAnalisisId', {{ $r->id }})"
                class="simo-btn-ghost text-indigo-500 hover:text-indigo-600">
                Ver análisis
            </button>
        @endif
    @endif
</div>
