<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Models\Pais;
use App\Models\ResultadoScraping;
use App\Services\Export\ResultadosCsvExporter;
use App\Services\ResultadoScrapingQueryService;
use App\Services\Scraper\ManualTitleGateBypass;
use App\Services\Scraper\TitleGateHealth;
use Illuminate\Support\Facades\Validator;
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

    #[Url]
    public string $filtroDeepSeek = '';

    #[Url(as: 'ids', except: '')]
    public string $filtroIds = '';

    public ?int $verAnalisisId = null;

    public ?int $seleccionadoId = null;

    /** @var string[] */
    private const SORT_COLUMNS = ['fecha_encontrado', 'titulo', 'keyword', 'pais', 'categoria', 'relevance_score'];

    /** @var string[] */
    private const SORT_DIRECTIONS = ['asc', 'desc'];

    public string $ordenar = 'fecha_encontrado';

    public string $direccion = 'desc';

    // ─── Updating hooks ────────────────────────────────────────────────────────

    public function updatingPaginators(): void
    {
        $this->seleccionadoId = null;
    }

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

    public function mount(): void
    {
        $this->validateTitleGateFilter();
    }

    public function updatingFiltroDeepSeek(): void
    {
        $this->resetPage();
    }

    public function updatedFiltroDeepSeek(): void
    {
        $this->validateTitleGateFilter();
    }

    private function validateTitleGateFilter(): void
    {
        $validator = Validator::make(['filtroDeepSeek' => $this->filtroDeepSeek], [
            'filtroDeepSeek' => ['nullable', 'in:held'],
        ]);
        if ($validator->fails()) {
            // Reject forged URL/updates without letting invalid state reach render SQL.
            $this->filtroDeepSeek = '';
            $this->addError('filtroDeepSeek', 'Seleccione un filtro DeepSeek válido.');
        } else {
            $this->resetValidation('filtroDeepSeek');
        }
    }

    public function limpiarFiltros(): void
    {
        $this->reset('busqueda', 'filtroPais', 'filtroCategoria', 'filtroLeido', 'filtroRelevante',
            'filtroDescartado', 'filtroArchivado', 'filtroGemini', 'filtroDeepSeek', 'filtroIds');
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

    public function enviarAGemini(int $id): void
    {
        $this->authorize('gestionar resultados');
        $result = app(ManualTitleGateBypass::class)->send($id);
        session()->flash('titleGateMessage', match ($result) {
            'scheduled' => 'Análisis programado en Gemini; se procesará con los pendientes.',
            'busy' => 'El artículo está siendo procesado. Reintente en unos momentos.',
            'unavailable' => 'Gemini está deshabilitado o sin configurar. Solicite su habilitación antes de enviar.',
            'dispatch_failed' => 'Envío manual guardado, pero no se pudo programar el trabajo. El escaneo periódico podrá recuperarlo.',
            default => 'El artículo ya no admite este envío; actualice la lista.',
        });
        unset($this->resultados, $this->pendingCount, $this->fuentesTabla);
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
            filtroDeepSeek: $this->filtroDeepSeek,
        );
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

    /**
     * Count of articles pending Gemini analysis (Design D9).
     *
     * Counts only:
     *  - pendienteAnalisisScraper (not processed or held by DeepSeek)
     *  - descartado = false (not discarded)
     *  - archivado_at IS NULL (not archived)
     *  - secundario_de IS NULL (only primary articles)
     *
     * Used to show/hide the "X procesando" header badge.
     * Memoized per request by Livewire's #[Computed] decorator.
     */
    #[Computed]
    public function pendingCount(): int
    {
        return ResultadoScraping::pendienteAnalisisScraper()
            ->where('descartado', false)
            ->whereNull('archivado_at')
            ->whereNull('secundario_de')
            ->count();
    }

    /** @return array<string, bool|string|null> */
    #[Computed]
    public function titleGateHealth(): array
    {
        return app(TitleGateHealth::class)->status();
    }

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

        return ResultadoScraping::with([
            'personas' => fn ($q) => $q->orderByDesc('threshold_passed')->orderByDesc('confianza'),
        ])->find($this->verAnalisisId);
    }

    /**
     * @return LengthAwarePaginator<ResultadoScraping>
     */
    #[Computed]
    public function resultados(): LengthAwarePaginator
    {
        return $this->getQuery()->paginate(25);
    }

    public function seleccionarResultado(int $id): void
    {
        $this->seleccionadoId = $this->resultados->getCollection()->contains('id', $id) ? $id : null;
        unset($this->seleccionado, $this->fuentesEvidencia, $this->enlacesEvidencia, $this->comparacionesTitulos);
    }

    public function cerrarComparacion(): void
    {
        $this->seleccionadoId = null;
        unset($this->seleccionado, $this->fuentesEvidencia, $this->enlacesEvidencia, $this->comparacionesTitulos);
    }

    #[Computed]
    public function seleccionado(): ?ResultadoScraping
    {
        return $this->resultados->getCollection()->firstWhere('id', $this->seleccionadoId);
    }

    /** @return Collection<int, Collection<int, ResultadoScraping>> */
    #[Computed]
    public function fuentesTabla(): Collection
    {
        // Batch-load summaries only; per-source analysis remains exclusive to explicit comparison.
        $rows = $this->resultados->getCollection();
        $rows->loadMissing(['secondaries.sitio', 'primary.sitio', 'primary.secondaries.sitio']);

        return $rows->mapWithKeys(static function (ResultadoScraping $row): array {
            $primary = $row->primary ?? $row;

            return [$row->id => collect([$primary])->concat($primary->secondaries->sortBy('id'))->values()];
        });
    }

    /** @return Collection<int, ResultadoScraping> */
    #[Computed]
    public function fuentesEvidencia(): Collection
    {
        $selected = $this->seleccionado;
        if ($selected === null) {
            return collect();
        }

        // Pending mode can expose a secondary. Follow only its recorded parent,
        // never infer matches or load details for the other chooser rows.
        $selected->loadMissing('primary');
        $primary = $selected->primary ?? $selected;
        $primary->loadMissing(['sitio', 'personas', 'secondaries.sitio', 'secondaries.personas']);

        return collect([$primary])->concat($primary->secondaries->sortBy('id'))->values();
    }

    /** @return Collection<int, string|null> */
    #[Computed]
    public function enlacesEvidencia(): Collection
    {
        return $this->fuentesEvidencia->mapWithKeys(static function (ResultadoScraping $fuente): array {
            $url = $fuente->url;
            $safe = filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);

            return [$fuente->id => $safe ? $url : null];
        });
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function comparacionesTitulos(): Collection
    {
        $primary = $this->fuentesEvidencia->first();

        return $this->fuentesEvidencia->mapWithKeys(static function (ResultadoScraping $fuente) use ($primary): array {
            $comparison = 'Título del primario registrado';
            if ($fuente->id !== $primary?->id) {
                $comparison = ! $primary?->titulo || ! $fuente->titulo
                    ? 'Título no disponible para comparar'
                    : ($fuente->titulo === $primary->titulo ? 'Título idéntico al primario' : 'Título diferente al primario');
            }

            return [$fuente->id => $comparison];
        });
    }

    public function render(): View
    {
        // Actions may remove a row from the current filter/page. Do not retain a
        // stale ID that could reopen comparison if that row later reappears.
        if ($this->seleccionadoId !== null && $this->seleccionado === null) {
            $this->cerrarComparacion();
        }

        return view('livewire.scraper.resultados', [
            'resultados'        => $this->resultados,
            'paises'            => $this->paises,
            'categorias'        => $this->categorias,
            'resultadoAnalisis' => $this->resultadoAnalisis,
            'fuentesTabla'      => $this->fuentesTabla,
            'seleccionado'      => $this->seleccionado,
            'fuentesEvidencia'  => $this->fuentesEvidencia,
            'enlacesEvidencia'  => $this->enlacesEvidencia,
            'comparacionesTitulos' => $this->comparacionesTitulos,
        ]);
    }
}
