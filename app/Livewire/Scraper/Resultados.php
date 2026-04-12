<?php

declare(strict_types=1);

namespace App\Livewire\Scraper;

use App\Enums\CategoriaCorreccion;
use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\Pais;
use App\Models\ResultadoScraping;
use App\Services\Normalization\NombreNormalizador;
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

    public string $busqueda = '';

    public string $filtroPais = '';

    public string $filtroCategoria = '';

    public string $filtroLeido = '';

    public string $filtroRelevante = '';

    public string $filtroDescartado = '0'; // Por defecto oculta descartados

    public string $filtroGemini = '';

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
        $query = $this->buildQuery();
        $filename = 'resultados_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($handle, ['ID', 'Keyword', 'URL', 'Sitio', 'Pais', 'Categoria', 'Titulo', 'Contexto', 'Relevance', 'Fecha', 'Gemini_Analizado', 'Gemini_PEP', 'Gemini_Categoria', 'Gemini_Nombre', 'Gemini_Cargo', 'Gemini_Confianza']);

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
                        $r->gemini_analyzed ? 'Si' : 'No',
                        $r->gemini_is_pep ? 'Si' : 'No',
                        $r->gemini_categoria ?? '',
                        $r->gemini_nombre ?? '',
                        $r->gemini_cargo ?? '',
                        $r->gemini_confianza ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    private function buildQuery()
    {
        $q = ResultadoScraping::with('sitio')
            ->orderBy($this->ordenar, $this->direccion);

        // Eager load feedback for current user (no N+1)
        if (Auth::check()) {
            $q->withFeedbackFromUser(Auth::id());
        }

        if ($this->busqueda) {
            $b = '%'.$this->busqueda.'%';
            $q->where(fn ($s) => $s->where('keyword', 'like', $b)
                ->orWhere('titulo', 'like', $b)
                ->orWhere('url', 'like', $b)
                ->orWhere('contexto', 'like', $b));
        }
        if ($this->filtroPais) {
            $q->where('pais', $this->filtroPais);
        }
        if ($this->filtroCategoria) {
            $q->where('categoria', $this->filtroCategoria);
        }
        if ($this->filtroLeido !== '') {
            $q->where('leido', (bool) $this->filtroLeido);
        }
        if ($this->filtroRelevante !== '') {
            if ($this->filtroRelevante === 'null') {
                $q->whereNull('relevante');
            } else {
                $q->where('relevante', (bool) $this->filtroRelevante);
            }
        }

        // Filtro descartados: '0' = solo activos, '1' = solo descartados, '' = todos
        if ($this->filtroDescartado === '0') {
            $q->where('descartado', false);
        } elseif ($this->filtroDescartado === '1') {
            $q->where('descartado', true);
        }

        // Filtro Gemini
        if ($this->filtroGemini === 'pending') {
            $q->where('gemini_analyzed', false);
        } elseif ($this->filtroGemini === 'pep') {
            $q->where('gemini_analyzed', true)->where('gemini_is_pep', true)->where('gemini_categoria', 'PEP');
        } elseif ($this->filtroGemini === 'opi') {
            $q->where('gemini_analyzed', true)->where('gemini_is_pep', true)->where('gemini_categoria', 'OPI');
        } elseif ($this->filtroGemini === 'not_pep') {
            $q->where('gemini_analyzed', true)->where('gemini_is_pep', false);
        }

        return $q;
    }

    // ─── Computed ─────────────────────────────────────────────────────────────

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

    #[Computed]
    public function resultadoAnalisis(): ?ResultadoScraping
    {
        if (! $this->verAnalisisId) {
            return null;
        }

        return ResultadoScraping::find($this->verAnalisisId);
    }

    public function render()
    {
        $resultados = $this->buildQuery()->paginate(25);

        return view('livewire.scraper.resultados', [
            'resultados' => $resultados,
            'paises' => $this->paises,
            'categorias' => $this->categorias,
            'resultadoAnalisis' => $this->resultadoAnalisis,
        ]);
    }
}
