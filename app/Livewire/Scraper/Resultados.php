<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Enums\CategoriaCorreccion;
use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\Pais;
use App\Models\ResultadoScraping;
use App\Services\Export\ResultadosCsvExporter;
use App\Services\Normalization\NombreNormalizador;
use App\Services\ResultadoScrapingQueryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
    public string $filtroGemini = 'pep';

    public ?int $verAnalisisId = null;

    public string $ordenar = 'fecha_encontrado';

    public string $direccion = 'desc';

    // ─── Feedback props ────────────────────────────────────────────────────────

    public ?int $feedbackModalId = null;

    public ?string $feedbackCategoriaCorregida = null;

    public ?string $feedbackNombreCorregido = null;

    public ?string $feedbackCargoCorregido = null;

    public ?bool $feedbackIsPepCorregido = null;

    public string $feedbackMotivo = '';

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

    public function updatingFiltroGemini(): void
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

    public function exportarCsv(): StreamedResponse
    {
        $exporter = new ResultadosCsvExporter;

        return $exporter->stream(
            $this->getQuery(),
            'resultados_'.now()->format('Ymd_His').'.csv',
        );
    }

    // ─── Feedback actions ─────────────────────────────────────────────────────

    public function guardarFeedbackCorrecto(int $id): void
    {
        $this->authorize('dar feedback clasificaciones');

        $resultado = ResultadoScraping::findOrFail($id);

        ClasificacionFeedback::updateOrCreate(
            ['resultado_scraping_id' => $id, 'usuario_id' => Auth::id()],
            [
                'tipo' => TipoFeedback::Correcto,
                'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
            ]
        );

        session()->flash('message', 'Feedback guardado correctamente.');
    }

    public function abrirModalFeedbackIncorrecto(int $id): void
    {
        $this->authorize('dar feedback clasificaciones');

        $resultado = ResultadoScraping::withFeedbackFromUser(Auth::id())->findOrFail($id);
        $this->feedbackModalId = $id;

        // Pre-fill from existing feedback if any
        $existing = $resultado->feedback->first();
        if ($existing) {
            $this->feedbackCategoriaCorregida = $existing->corregido_categoria?->value;
            $this->feedbackNombreCorregido = $existing->corregido_nombre;
            $this->feedbackCargoCorregido = $existing->corregido_cargo;
            $this->feedbackIsPepCorregido = $existing->corregido_is_pep;
            $this->feedbackMotivo = $existing->motivo ?? '';
        } else {
            $this->reset(['feedbackCategoriaCorregida', 'feedbackNombreCorregido', 'feedbackCargoCorregido', 'feedbackIsPepCorregido', 'feedbackMotivo']);
        }
    }

    public function guardarFeedbackIncorrecto(): void
    {
        $this->authorize('dar feedback clasificaciones');

        $this->validate($this->rulesFeedbackIncorrecto());

        $resultado = ResultadoScraping::findOrFail($this->feedbackModalId);

        $normalizador = app(NombreNormalizador::class);
        $nombreNormalizado = $normalizador->normalizeNullable($this->feedbackNombreCorregido)?->normalized;

        ClasificacionFeedback::updateOrCreate(
            ['resultado_scraping_id' => $this->feedbackModalId, 'usuario_id' => Auth::id()],
            [
                'tipo' => TipoFeedback::Incorrecto,
                'clasificacion_snapshot' => $resultado->toGeminiSnapshot(),
                'corregido_is_pep' => $this->feedbackIsPepCorregido,
                'corregido_categoria' => $this->feedbackCategoriaCorregida ? CategoriaCorreccion::from($this->feedbackCategoriaCorregida) : null,
                'corregido_nombre' => $this->feedbackNombreCorregido,
                'corregido_nombre_normalizado' => $nombreNormalizado,
                'corregido_cargo' => $this->feedbackCargoCorregido,
                'motivo' => $this->feedbackMotivo,
            ]
        );

        $this->cerrarModalFeedback();
        session()->flash('message', 'Feedback guardado correctamente.');
    }

    public function cerrarModalFeedback(): void
    {
        $this->feedbackModalId = null;
        $this->reset([
            'feedbackCategoriaCorregida',
            'feedbackNombreCorregido',
            'feedbackCargoCorregido',
            'feedbackIsPepCorregido',
            'feedbackMotivo',
        ]);
        $this->resetValidation();
    }

    // ─── Validation rules ─────────────────────────────────────────────────────

    protected function rulesFeedbackIncorrecto(): array
    {
        return [
            'feedbackCategoriaCorregida' => ['required', \Illuminate\Validation\Rule::enum(CategoriaCorreccion::class)],
            'feedbackMotivo' => 'required|string|min:10|max:1000',
            'feedbackNombreCorregido' => 'nullable|string|max:200',
            'feedbackCargoCorregido' => 'nullable|string|max:200',
            'feedbackIsPepCorregido' => 'nullable|boolean',
        ];
    }

    // ─── Query builder ────────────────────────────────────────────────────────

    private function getQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return (new ResultadoScrapingQueryService)->buildQuery(
            busqueda: $this->busqueda,
            filtroPais: $this->filtroPais,
            filtroCategoria: $this->filtroCategoria,
            filtroLeido: $this->filtroLeido,
            filtroRelevante: $this->filtroRelevante,
            filtroDescartado: $this->filtroDescartado,
            filtroGemini: $this->filtroGemini,
            ordenar: $this->ordenar,
            direccion: $this->direccion,
            userId: Auth::id(),
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

    public function render(): View
    {
        $resultados = $this->getQuery()->paginate(25);

        return view('livewire.scraper.resultados', [
            'resultados' => $resultados,
            'paises' => $this->paises,
            'categorias' => $this->categorias,
            'resultadoAnalisis' => $this->resultadoAnalisis,
        ]);
    }
}
