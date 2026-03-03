<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Resultados Scraping</h1>
        <div class="flex gap-2">
            <a href="{{ route('scraper.sitios') }}" class="text-sm text-blue-600 hover:underline">Sitios</a>
            <span class="text-gray-300">|</span>
            <a href="{{ route('scraper.keywords') }}" class="text-sm text-blue-600 hover:underline">Keywords</a>
            <span class="text-gray-300">|</span>
            <button wire:click="exportarCsv" class="text-sm text-green-600 hover:underline">Exportar CSV</button>
        </div>
    </div>

    {{-- Filtros --}}
    <div class="bg-white rounded-lg shadow p-3 mb-4 grid grid-cols-2 md:grid-cols-5 gap-2">
        <input wire:model.live.debounce.400ms="busqueda" type="text" placeholder="Buscar keyword, URL, titulo..."
               class="col-span-2 md:col-span-1 border rounded px-2 py-1.5 text-sm w-full" />

        <select wire:model.live="filtroPais" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todos los paises</option>
            @foreach($paises as $p)
                <option value="{{ $p->codigo }}">{{ $p->nombre }}</option>
            @endforeach
        </select>

        <select wire:model.live="filtroCategoria" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todas las categorias</option>
            @foreach($categorias as $cat)
                <option value="{{ $cat }}">{{ $cat }}</option>
            @endforeach
        </select>

        <select wire:model.live="filtroLeido" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todos (leido)</option>
            <option value="0">Sin leer</option>
            <option value="1">Leidos</option>
        </select>

        <select wire:model.live="filtroRelevante" class="border rounded px-2 py-1.5 text-sm">
            <option value="">Todos (relevante)</option>
            <option value="1">Relevantes</option>
            <option value="0">No relevantes</option>
            <option value="null">Sin clasificar</option>
        </select>
    </div>

    {{-- Tabla --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Keyword / Titulo</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Sitio / Pais</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase w-16">Score</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($resultados as $r)
                    <tr class="{{ $r->leido ? 'bg-white' : 'bg-blue-50' }} hover:bg-gray-50">
                        <td class="px-3 py-2 max-w-xs">
                            <div class="font-medium text-gray-800">{{ $r->keyword }}</div>
                            @if($r->titulo)
                                <div class="text-gray-600 text-xs truncate" title="{{ $r->titulo }}">{{ Str::limit($r->titulo, 80) }}</div>
                            @endif
                            <a href="{{ $r->url }}" target="_blank"
                               class="text-xs text-blue-500 hover:underline truncate block max-w-xs"
                               title="{{ $r->url }}">{{ Str::limit($r->url, 70) }}</a>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600">
                            <div>{{ $r->sitio?->nombre ?? '—' }}</div>
                            <div class="text-gray-400">{{ $r->pais }} {{ $r->categoria ? '/ '.$r->categoria : '' }}</div>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <span class="text-xs font-bold {{ $r->relevance_score >= 70 ? 'text-green-600' : ($r->relevance_score >= 40 ? 'text-amber-600' : 'text-gray-400') }}">
                                {{ $r->relevance_score }}
                            </span>
                            @if($r->found_in_title)
                                <div class="text-xs text-green-500">titulo</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-500">
                            {{ $r->fecha_encontrado->format('d/m/y H:i') }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-1 flex-wrap">
                                @if(!$r->leido)
                                    <button wire:click="marcarLeido({{ $r->id }})"
                                            class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-0.5 rounded">
                                        Leido
                                    </button>
                                @endif
                                <button wire:click="marcarRelevante({{ $r->id }}, true)"
                                        class="text-xs {{ $r->relevante === true ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-green-100' }} px-2 py-0.5 rounded">
                                    Si
                                </button>
                                <button wire:click="marcarRelevante({{ $r->id }}, false)"
                                        class="text-xs {{ $r->relevante === false ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-red-100' }} px-2 py-0.5 rounded">
                                    No
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">Sin resultados.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t">
            {{ $resultados->links() }}
        </div>
    </div>
</div>
