<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Scraper;

use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResultadosIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.gemini.enabled' => false]);
        $this->seed(RolesPermisosSeeder::class);
    }

    private function createAnalyzedResultado(SitioWeb $sitio, string $urlSuffix = ''): ResultadoScraping
    {
        return ResultadoScraping::create([
            'url' => 'https://test.com/article'.$urlSuffix,
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
            'gemini_motivo' => 'Es PEP.',
        ]);
    }

    // ─── 8.1 N+1 Prevention ───────────────────────────────────────────────────

    public function test_eager_loading_prevents_n_plus_1(): void
    {
        $sitio = SitioWeb::factory()->create();
        $user = User::factory()->create();

        // Create 5 resultados each with feedback
        for ($i = 0; $i < 5; $i++) {
            $resultado = $this->createAnalyzedResultado($sitio, '-'.$i);
            ClasificacionFeedback::create([
                'resultado_scraping_id' => $resultado->id,
                'usuario_id' => $user->id,
                'tipo' => TipoFeedback::Correcto,
                'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
            ]);
        }

        DB::enableQueryLog();

        $resultados = ResultadoScraping::withFeedbackFromUser($user->id)->with('sitio')->get();
        // Simulate blade iteration accessing feedback
        foreach ($resultados as $r) {
            $_ = $r->feedback->first();
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Should be ≤ 3 queries: 1 for resultados, 1 for feedback (eager), 1 for sitios
        $this->assertLessThanOrEqual(3, count($queries), 'Too many queries — N+1 detected');
    }

    // ─── 8.2 Cascade Delete ───────────────────────────────────────────────────

    public function test_deleting_resultado_cascades_feedback_deletion(): void
    {
        $sitio = SitioWeb::factory()->create();
        $resultado = $this->createAnalyzedResultado($sitio);
        $user = User::factory()->create();

        $feedback = ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
        ]);

        $resultadoId = $resultado->id;
        $resultado->delete();

        $this->assertSame(0, ClasificacionFeedback::where('resultado_scraping_id', $resultadoId)->count());
    }

    // ─── 8.3 Restrict Delete (usuario with feedback) ──────────────────────────

    public function test_deleting_user_with_feedback_raises_integrity_error(): void
    {
        $sitio = SitioWeb::factory()->create();
        $resultado = $this->createAnalyzedResultado($sitio);
        $user = User::factory()->create();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
        ]);

        // SQLite with RESTRICT should raise an error when trying to delete a user with feedback
        $this->expectException(\Illuminate\Database\QueryException::class);

        $user->delete();
    }
}
