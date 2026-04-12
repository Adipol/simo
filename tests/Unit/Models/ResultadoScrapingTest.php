<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResultadoScrapingTest extends TestCase
{
    use RefreshDatabase;

    private function createResultado(array $overrides = []): ResultadoScraping
    {
        $sitio = SitioWeb::factory()->create();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://test.com/article',
            'keyword' => 'test',
            'sitio_id' => $sitio->id,
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'relevante' => null,
            'descartado' => false,
            'gemini_analyzed' => true,
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_nombre' => 'Juan Perez',
            'gemini_cargo' => 'Ministro',
            'gemini_confianza' => 85,
            'gemini_motivo' => 'Es PEP',
        ], $overrides));
    }

    // ─── toGeminiSnapshot ─────────────────────────────────────────────────────

    public function test_to_gemini_snapshot_returns_array_with_correct_keys(): void
    {
        $resultado = $this->createResultado([
            'gemini_is_pep' => true,
            'gemini_categoria' => 'PEP',
            'gemini_confianza' => 90,
            'gemini_nombre' => 'Maria Lopez',
            'gemini_cargo' => 'Presidenta',
        ]);

        $snapshot = $resultado->toGeminiSnapshot();

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('is_pep', $snapshot);
        $this->assertArrayHasKey('categoria', $snapshot);
        $this->assertArrayHasKey('confianza', $snapshot);
        $this->assertArrayHasKey('nombre', $snapshot);
        $this->assertArrayHasKey('cargo', $snapshot);
    }

    public function test_to_gemini_snapshot_values_match_gemini_fields(): void
    {
        $resultado = $this->createResultado([
            'gemini_is_pep' => true,
            'gemini_categoria' => 'OPI',
            'gemini_confianza' => 75,
            'gemini_nombre' => 'Carlos Mendoza',
            'gemini_cargo' => 'Senador',
        ]);

        $snapshot = $resultado->toGeminiSnapshot();

        $this->assertTrue($snapshot['is_pep']);
        $this->assertSame('OPI', $snapshot['categoria']);
        $this->assertSame(75, $snapshot['confianza']);
        $this->assertSame('Carlos Mendoza', $snapshot['nombre']);
        $this->assertSame('Senador', $snapshot['cargo']);
    }

    // ─── feedback() HasMany ───────────────────────────────────────────────────

    public function test_feedback_relation_returns_collection_of_clasificacion_feedback(): void
    {
        $resultado = $this->createResultado();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user1->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
        ]);
        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user2->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
        ]);

        $feedback = $resultado->feedback;

        $this->assertCount(2, $feedback);
        $this->assertInstanceOf(ClasificacionFeedback::class, $feedback->first());
    }

    // ─── withFeedbackFromUser scope ───────────────────────────────────────────

    public function test_scope_with_feedback_from_user_eager_loads_only_user_feedback(): void
    {
        $resultado = $this->createResultado();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $userA->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
        ]);
        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $userB->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
        ]);

        $resultados = ResultadoScraping::withFeedbackFromUser($userA->id)->get();

        $this->assertCount(1, $resultados);
        // Only userA's feedback is eager loaded
        $this->assertCount(1, $resultados->first()->feedback);
        $this->assertSame($userA->id, $resultados->first()->feedback->first()->usuario_id);
    }

    public function test_scope_with_feedback_from_user_does_not_cause_n_plus_1(): void
    {
        $sitio = SitioWeb::factory()->create();
        $userA = User::factory()->create();

        // Create 3 resultados with feedback from userA
        for ($i = 0; $i < 3; $i++) {
            $resultado = ResultadoScraping::create([
                'url' => 'https://test.com/article-'.$i,
                'keyword' => 'test',
                'sitio_id' => $sitio->id,
                'pais' => 'BO',
                'fecha_encontrado' => now(),
                'relevance_score' => 50,
                'leido' => false,
                'relevante' => null,
                'descartado' => false,
                'gemini_analyzed' => true,
                'gemini_is_pep' => true,
                'gemini_categoria' => 'PEP',
                'gemini_nombre' => 'Test',
                'gemini_cargo' => 'Test',
                'gemini_confianza' => 80,
                'gemini_motivo' => 'Test',
            ]);
            ClasificacionFeedback::create([
                'resultado_scraping_id' => $resultado->id,
                'usuario_id' => $userA->id,
                'tipo' => TipoFeedback::Correcto,
                'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 80, 'nombre' => 'Test', 'cargo' => 'Test'],
            ]);
        }

        DB::enableQueryLog();

        $resultados = ResultadoScraping::withFeedbackFromUser($userA->id)->get();
        // Access feedback on each (simulating blade iteration)
        foreach ($resultados as $r) {
            $_ = $r->feedback->first();
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Should be 2 queries: 1 for resultados, 1 for all feedback (eager loaded)
        $this->assertLessThanOrEqual(2, count($queries));
    }
}
