<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\TipoFeedback;
use App\Models\ClasificacionFeedback;
use App\Models\ResultadoScraping;
use App\Models\SitioWeb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function createFeedbackFor(User $user, ResultadoScraping $resultado): ClasificacionFeedback
    {
        return ClasificacionFeedback::create([
            'resultado_scraping_id' => $resultado->id,
            'usuario_id' => $user->id,
            'tipo' => TipoFeedback::Correcto,
            'clasificacion_snapshot' => ['is_pep' => true, 'categoria' => 'PEP', 'confianza' => 85, 'nombre' => 'Test', 'cargo' => 'Test'],
        ]);
    }

    public function test_clasificaciones_feedback_relation_returns_collection_of_clasificacion_feedback(): void
    {
        $user = User::factory()->create();
        $sitio = SitioWeb::factory()->create();

        $resultado1 = ResultadoScraping::factory()->for($sitio, 'sitio')->create();
        $resultado2 = ResultadoScraping::factory()->for($sitio, 'sitio')->create();

        $this->createFeedbackFor($user, $resultado1);
        $this->createFeedbackFor($user, $resultado2);

        $feedback = $user->clasificacionesFeedback;

        $this->assertCount(2, $feedback);
        $this->assertInstanceOf(ClasificacionFeedback::class, $feedback->first());
    }

    public function test_clasificaciones_feedback_only_returns_this_users_feedback(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $sitio = SitioWeb::factory()->create();

        $resultado1 = ResultadoScraping::factory()->for($sitio, 'sitio')->create();
        $resultado2 = ResultadoScraping::factory()->for($sitio, 'sitio')->create();

        $this->createFeedbackFor($user1, $resultado1);
        $this->createFeedbackFor($user2, $resultado2);

        $feedback = $user1->clasificacionesFeedback;

        $this->assertCount(1, $feedback);
        $this->assertSame($user1->id, $feedback->first()->usuario_id);
    }
}
