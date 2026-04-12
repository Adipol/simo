<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CategoriaCorreccion;
use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClasificacionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function createSitio(): SitioWeb
    {
        return SitioWeb::create([
            'url' => 'https://test.com',
            'nombre' => 'Test Site',
            'pais' => 'BO',
            'activo' => true,
        ]);
    }

    private function createResultado(SitioWeb $sitio): ResultadoScraping
    {
        return ResultadoScraping::create([
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
        ]);
    }

    private function createUser(): User
    {
        return User::factory()->create();
    }

    private function snapshot(): array
    {
        return [
            'is_pep' => true,
            'categoria' => 'PEP',
            'confianza' => 85,
            'nombre' => 'Juan Perez',
            'cargo' => 'Ministro',
        ];
    }

    // ─── Fillable ─────────────────────────────────────────────────────────────

    public function test_fillable_includes_all_required_fields(): void
    {
        $model = new ClasificacionFeedback;
        $fillable = $model->getFillable();

        $this->assertContains('resultado_scraping_id', $fillable);
        $this->assertContains('usuario_id', $fillable);
        $this->assertContains('tipo', $fillable);
        $this->assertContains('clasificacion_snapshot', $fillable);
        $this->assertContains('corregido_is_pep', $fillable);
        $this->assertContains('corregido_categoria', $fillable);
        $this->assertContains('corregido_nombre', $fillable);
        $this->assertContains('corregido_cargo', $fillable);
        $this->assertContains('motivo', $fillable);
    }

    // ─── Casts ────────────────────────────────────────────────────────────────

    public function test_clasificacion_snapshot_is_cast_to_array(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        $feedback = ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $this->assertIsArray($feedback->fresh()->clasificacion_snapshot);
        $this->assertSame('PEP', $feedback->fresh()->clasificacion_snapshot['categoria']);
    }

    public function test_corregido_is_pep_is_cast_to_boolean(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        $feedback = ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $this->snapshot(),
            'corregido_is_pep' => true,
        ]);

        $this->assertIsBool($feedback->fresh()->corregido_is_pep);
        $this->assertTrue($feedback->fresh()->corregido_is_pep);
    }

    public function test_tipo_is_cast_to_tipo_feedback_enum(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        $feedback = ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $this->assertInstanceOf(TipoFeedback::class, $feedback->fresh()->tipo);
        $this->assertSame(TipoFeedback::Correcto, $feedback->fresh()->tipo);
    }

    public function test_corregido_categoria_is_cast_to_categoria_correccion_enum(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        $feedback = ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $this->snapshot(),
            'corregido_categoria' => CategoriaCorreccion::OPI,
        ]);

        $this->assertInstanceOf(CategoriaCorreccion::class, $feedback->fresh()->corregido_categoria);
        $this->assertSame(CategoriaCorreccion::OPI, $feedback->fresh()->corregido_categoria);
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function test_resultado_scraping_relation_returns_resultado_scraping_model(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        $feedback = ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $this->assertInstanceOf(ResultadoScraping::class, $feedback->resultadoScraping);
        $this->assertSame($resultado->id, $feedback->resultadoScraping->id);
    }

    public function test_usuario_relation_returns_user_model(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        $feedback = ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $this->assertInstanceOf(User::class, $feedback->usuario);
        $this->assertSame($user->id, $feedback->usuario->id);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function test_scope_correctos_returns_only_correcto_feedbacks(): void
    {
        $sitio = $this->createSitio();
        $resultado1 = $this->createResultado($sitio);
        $resultado2 = $this->createResultado($sitio);
        $user = $this->createUser();
        $user2 = $this->createUser();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado1->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado2->id,
            'usuario_id' => $user2->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $result = ClasificacionFeedback::correctos()->get();

        $this->assertCount(1, $result);
        $this->assertSame(TipoFeedback::Correcto, $result->first()->tipo);
    }

    public function test_scope_incorrectos_returns_only_incorrecto_feedbacks(): void
    {
        $sitio = $this->createSitio();
        $resultado1 = $this->createResultado($sitio);
        $resultado2 = $this->createResultado($sitio);
        $user = $this->createUser();
        $user2 = $this->createUser();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado1->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado2->id,
            'usuario_id' => $user2->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $result = ClasificacionFeedback::incorrectos()->get();

        $this->assertCount(1, $result);
        $this->assertSame(TipoFeedback::Incorrecto, $result->first()->tipo);
    }

    public function test_scope_por_usuario_filters_by_user_id(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user1 = $this->createUser();
        $user2 = $this->createUser();

        // Create resultado for user2 (needs different resultado)
        $resultado2 = ResultadoScraping::create([
            'url' => 'https://test.com/article2',
            'keyword' => 'test2',
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
            'usuario_id' => $user1->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado2->id,
            'usuario_id' => $user2->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $result = ClasificacionFeedback::porUsuario($user1->id)->get();

        $this->assertCount(1, $result);
        $this->assertSame($user1->id, $result->first()->usuario_id);
    }

    public function test_scope_por_resultado_filters_by_resultado_id(): void
    {
        $sitio = $this->createSitio();
        $resultado1 = $this->createResultado($sitio);
        $resultado2 = ResultadoScraping::create([
            'url' => 'https://test.com/article2',
            'keyword' => 'test2',
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
        $user1 = $this->createUser();
        $user2 = $this->createUser();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado1->id,
            'usuario_id' => $user1->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado2->id,
            'usuario_id' => $user2->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $result = ClasificacionFeedback::porResultado($resultado1->id)->get();

        $this->assertCount(1, $result);
        $this->assertSame($resultado1->id, $result->first()->resultado_scraping_id);
    }

    // ─── Unique Constraint ─────────────────────────────────────────────────────

    public function test_unique_constraint_prevents_duplicate_feedback_per_user_per_resultado(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);

        $this->expectException(QueryException::class);

        ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Incorrecto,
            'clasificacion_snapshot' => $this->snapshot(),
        ]);
    }

    // ─── Factory ──────────────────────────────────────────────────────────────

    public function test_factory_creates_valid_records(): void
    {
        $sitio = $this->createSitio();
        $resultado = $this->createResultado($sitio);
        $user = $this->createUser();

        $feedback = ClasificacionFeedback::factory()
            ->for($resultado, 'resultadoScraping')
            ->for($user, 'usuario')
            ->create();

        $this->assertNotNull($feedback->id);
        $this->assertSame($resultado->id, $feedback->resultado_scraping_id);
        $this->assertSame($user->id, $feedback->usuario_id);
        $this->assertInstanceOf(TipoFeedback::class, $feedback->tipo);
        $this->assertIsArray($feedback->clasificacion_snapshot);
    }
}
