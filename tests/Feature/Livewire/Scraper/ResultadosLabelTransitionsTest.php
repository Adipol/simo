<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Livewire\Scraper\Resultados;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ResultadosLabelTransitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.gemini.enabled' => false]);
        config(['services.dedupe.enabled' => false]);
    }

    private function createResultado(array $attributes = []): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://test.example.com/article-'.uniqid(),
            'keyword' => 'label transition',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => false,
            'descartado' => false,
            'gemini_analyzed' => true,
        ], $attributes));
    }

    public function test_marking_as_relevant_clears_discarded_label(): void
    {
        $resultado = $this->createResultado([
            'relevante' => false,
            'descartado' => true,
        ]);

        Livewire::test(Resultados::class)
            ->call('marcarRelevante', $resultado->id, true);

        $resultado->refresh();

        $this->assertTrue($resultado->relevante);
        $this->assertFalse($resultado->descartado);
    }

    public function test_clearing_relevant_label_does_not_discard_result(): void
    {
        $resultado = $this->createResultado([
            'relevante' => true,
            'descartado' => false,
        ]);

        Livewire::test(Resultados::class)
            ->call('marcarRelevante', $resultado->id, false);

        $resultado->refresh();

        $this->assertFalse($resultado->relevante);
        $this->assertFalse($resultado->descartado);
    }

    public function test_discarding_clears_relevance_and_marks_result_as_read(): void
    {
        $resultado = $this->createResultado([
            'leido' => false,
            'relevante' => true,
            'descartado' => false,
        ]);

        Livewire::test(Resultados::class)
            ->call('descartar', $resultado->id);

        $resultado->refresh();

        $this->assertTrue($resultado->descartado);
        $this->assertFalse($resultado->relevante);
        $this->assertTrue($resultado->leido);
    }

    public function test_restoring_discarded_result_keeps_it_unlabeled(): void
    {
        $resultado = $this->createResultado([
            'relevante' => false,
            'descartado' => true,
        ]);

        Livewire::test(Resultados::class)
            ->call('restaurar', $resultado->id);

        $resultado->refresh();

        $this->assertFalse($resultado->descartado);
        $this->assertFalse($resultado->relevante);
    }
}
