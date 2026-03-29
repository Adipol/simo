<?php

namespace App\Livewire\Scraper;

use App\Models\Pais;
use App\Models\ResultadoScraping;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app', ['title' => 'Resultados Scraping'])]
class Resultados extends Component
{
    use WithPagination;

    public string $busqueda = '';
    public string $filtroPais = '';
    public string $filtroCategoria = '';
    public string $filtroLeido = '';
    public string $filtroRelevante = '';
    public string $filtroDescartado = '0'; // Por defecto oculta descartados
    public string $ordenar = 'fecha_encontrado';
    public string $direccion = 'desc';

    public function updatingBusqueda(): void        { $this->resetPage(); }
    public function updatingFiltroPais(): void       { $this->resetPage(); }
    public function updatingFiltroCategoria(): void  { $this->resetPage(); }
    public function updatingFiltroLeido(): void      { $this->resetPage(); }
    public function updatingFiltroRelevante(): void  { $this->resetPage(); }
    public function updatingFiltroDescartado(): void { $this->resetPage(); }

    public function marcarLeido(int $id): void
    {
        ResultadoScraping::where('id', $id)->update(['leido' => true]);
    }

    public function marcarRelevante(int $id, bool $valor): void
    {
        ResultadoScraping::where('id', $id)->update(['relevante' => $valor]);
    }

    public function descartar(int $id): void
    {
        ResultadoScraping::where('id', $id)->update([
            'descartado' => true,
            'leido'      => true, // marcar leido tambien
        ]);
    }

    public function restaurar(int $id): void
    {
        ResultadoScraping::where('id', $id)->update(['descartado' => false]);
    }

    public function exportarCsv(): StreamedResponse
    {
        $query    = $this->buildQuery();
        $filename = 'resultados_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputs($handle, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($handle, ['ID', 'Keyword', 'URL', 'Sitio', 'Pais', 'Categoria', 'Titulo', 'Contexto', 'Relevance', 'Fecha']);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $r) {
                    fputcsv($handle, [
                        $r->id,
                        $r->keyword,
                        $r->url,
                        $r->sitio?->nombre ?? '',
                        $r->pais,
                        $r->categoria ?? '',
                        $r->titulo ?? '',
                        $r->contexto ?? '',
                        $r->relevance_score,
                        $r->fecha_encontrado->format('Y-m-d H:i:s'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function buildQuery()
    {
        $q = ResultadoScraping::with('sitio')->orderBy($this->ordenar, $this->direccion);

        if ($this->busqueda) {
            $b = '%' . $this->busqueda . '%';
            $q->where(fn($s) => $s->where('keyword', 'like', $b)
                ->orWhere('titulo', 'like', $b)
                ->orWhere('url', 'like', $b)
                ->orWhere('contexto', 'like', $b));
        }
        if ($this->filtroPais)      $q->where('pais', $this->filtroPais);
        if ($this->filtroCategoria) $q->where('categoria', $this->filtroCategoria);
        if ($this->filtroLeido !== '') $q->where('leido', (bool)$this->filtroLeido);
        if ($this->filtroRelevante !== '') {
            if ($this->filtroRelevante === 'null') $q->whereNull('relevante');
            else $q->where('relevante', (bool)$this->filtroRelevante);
        }

        // Filtro descartados: '0' = solo activos, '1' = solo descartados, '' = todos
        if ($this->filtroDescartado === '0') {
            $q->where('descartado', false);
        } elseif ($this->filtroDescartado === '1') {
            $q->where('descartado', true);
        }

        return $q;
    }

    #[Computed]
    public function paises()
    {
        return Pais::orderBy('nombre')->get();
    }

    #[Computed]
    public function categorias()
    {
        return ResultadoScraping::select('categoria')
            ->distinct()
            ->whereNotNull('categoria')
            ->orderBy('categoria')
            ->pluck('categoria');
    }

    public function render()
    {
        $resultados = $this->buildQuery()->paginate(25);

        return view('livewire.scraper.resultados', [
            'resultados' => $resultados,
            'paises'     => $this->paises,
            'categorias' => $this->categorias,
        ]);
    }
}
