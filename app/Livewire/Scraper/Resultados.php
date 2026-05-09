<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Models\Pais;
use App\Models\ResultadoScraping;
use App\Services\Export\ResultadosCsvExporter;
use App\Services\ResultadoScrapingQueryService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app', ['title' => 'Resultados Scraping'])]
class Resultados extends Component
{
    use WithPagination;

    #[Url]
    public string $busqueda = '';

    #[Url]
    public string $filtroPais = '';

    #[Url]
    public string $filtroCategoria = '';

    #[Url]
    public string $filtroLeido = '';

    #[Url]
    public string $filtroRelevante = '';

    #[Url]
    public string $filtroDescartado = '0'; // Por defecto oculta descartados

    #[Url]
    public string $filtroArchivado = '0'; // Por defecto oculta archivados

    #[Url]
    public string $filtroGemini = '';

    #[Url(as: 'ids', except: '')]
    public string $filtroIds = '';

    public ?int $verAnalisisId = null;

    /** @var string[] */
    private const SORT_COLUMNS = ['fecha_encontrado', 'titulo', 'keyword', 'pais', 'categoria', 'relevance_score'];

    /** @var string[] */
    private const SORT_DIRECTIONS = ['asc', 'desc'];

    public string $ordenar = 'fecha_encontrado';

    public string $direccion = 'desc';

    // ─── Updating hooks ────────────────────────────────────────────────────────

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroPais(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroCategoria(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroLeido(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroRelevante(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroDescartado(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroArchivado(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroGemini(): void
    {
        $this->resetPage();
    }

    public function updatingFiltroIds(): void
    {
        $this->resetPage();
    }

    // ─── Existing actions ─────────────────────────────────────────────────────

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
            'leido' => true, // marcar leido tambien
        ]);
    }

    public function restaurar(int $id): void
    {
        ResultadoScraping::where('id', $id)->update(['descartado' => false]);
    }

    public function archivar(int $id): void
    {
        $this->authorize('gestionar resultados');

        ResultadoScraping::where('id', $id)->update([
            'archivado_at' => now(),
            'leido' => true,
        ]);
    }

    public function desarchivar(int $id): void
    {
        $this->authorize('gestionar resultados');

        ResultadoScraping::where('id', $id)->update(['archivado_at' => null]);
    }

    public function exportarCsv(): StreamedResponse
    {
        $exporter = new ResultadosCsvExporter;

        return $exporter->stream(
            $this->getQuery(),
            'resultados_'.now()->format('Ymd_His').'.csv',
        );
    }

    // ─── Query builder ────────────────────────────────────────────────────────

    private function getQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Whitelist sort column and direction to prevent arbitrary column exposure
        $ordenar   = in_array($this->ordenar, self::SORT_COLUMNS, true) ? $this->ordenar : 'fecha_encontrado';
        $direccion = in_array($this->direccion, self::SORT_DIRECTIONS, true) ? $this->direccion : 'desc';

        return (new ResultadoScrapingQueryService)->buildQuery(
            busqueda: $this->busqueda,
            filtroPais: $this->filtroPais,
            filtroCategoria: $this->filtroCategoria,
            filtroLeido: $this->filtroLeido,
            filtroRelevante: $this->filtroRelevante,
            filtroDescartado: $this->filtroDescartado,
            filtroArchivado: $this->filtroArchivado,
            filtroGemini: $this->filtroGemini,
            filtroIds: $this->filtroIds,
            ordenar: $ordenar,
            direccion: $direccion,
        );
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

    #[Computed]
    public function paises(): Collection
    {
        return Pais::orderBy('nombre')->get();
    }

    #[Computed]
    public function categorias(): Collection
    {
        return ResultadoScraping::select('categoria')
            ->distinct()
            ->whereNotNull('categoria')
            ->orderBy('categoria')
            ->pluck('categoria');
    }

    #[Computed]
    public function resultadoAnalisis(): ?ResultadoScraping
    {
        if (! $this->verAnalisisId) {
            return null;
        }

        return ResultadoScraping::find($this->verAnalisisId);
    }

    /**
     * @return LengthAwarePaginator<ResultadoScraping>
     */
    #[Computed]
    public function resultados(): LengthAwarePaginator
    {
        return $this->getQuery()->paginate(25);
    }

    public function render(): View
    {
        return view('livewire.scraper.resultados', [
            'resultados'        => $this->resultados,
            'paises'            => $this->paises,
            'categorias'        => $this->categorias,
            'resultadoAnalisis' => $this->resultadoAnalisis,
        ]);
    }
}
