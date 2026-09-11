<?php

declare(strict_types=1);

namespace Tests\Feature\Pep;

use App\Enums\CambioFeedStatus;
use App\Models\AuthorityRemovalReview;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\User;
use App\Services\Pep\CambioDiagnosticExportService;
use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CambioDiagnosticExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.enabled' => false]);
        $this->seed(RolesPermisosSeeder::class);
    }

    public function test_download_route_requires_authentication_active_account_and_existing_permission(): void
    {
        $this->get(route('pep.cambios.diagnostic-export'))
            ->assertRedirect(route('login'));

        $operator = $this->userWithRole('operador');
        $this->actingAs($operator)
            ->get(route('pep.cambios.diagnostic-export'))
            ->assertForbidden();

        $inactiveSupervisor = $this->userWithRole('supervisor', false);
        $this->actingAs($inactiveSupervisor)
            ->get(route('pep.cambios.diagnostic-export'))
            ->assertRedirect(route('login'));

        $supervisor = $this->userWithRole('supervisor');
        $this->actingAs($supervisor)
            ->get(route('pep.cambios.diagnostic-export'))
            ->assertOk();
    }

    public function test_streamed_download_has_safe_headers_and_only_allowlisted_fields(): void
    {
        $supervisor = $this->userWithRole('supervisor');
        $actor = User::factory()->create(['activo' => true]);
        $source = Fuente::factory()->create([
            'nombre' => 'Public Source',
            'organismo' => 'Public Agency',
            'pais' => 'BO',
            'url' => 'https://public.example.test/authorities',
        ]);
        $cambio = Cambio::withoutEvents(static fn (): Cambio => Cambio::factory()->create([
            'fuente_id' => $source->id,
            'fecha' => now()->subDay(),
            'hash_anterior' => 'sensitive-old-hash',
            'hash_nuevo' => 'sensitive-new-hash',
            'lineas_nuevas' => 2,
            'lineas_quitadas' => 1,
            'diff_texto' => "+New Person\n-Old Person",
            'posibles_peps' => "New Person\nOld Person",
            'autoridades_eventos_json' => [
                'version' => 1,
                'events' => [[
                    'type' => 'reemplazo',
                    'old' => ['cargo' => 'Director', 'persona' => 'Old Person', 'actor_id' => 999],
                    'new' => ['cargo' => 'Director', 'persona' => 'New Person'],
                    'evidence' => 'sensitive-event-evidence',
                ]],
                'filesystem_path' => '/private/authority.json',
            ],
            'feed_status' => CambioFeedStatus::Primary,
            'revisado' => true,
            'gemini_analyzed' => true,
            'gemini_analyzed_at' => now()->subHour(),
            'gemini_analisis_json' => [
                'persona_removida' => 'Old Person',
                'persona_nueva' => 'New Person',
                'cargo' => 'Director',
                'es_mae' => true,
                'riesgo' => 'alto',
                'analisis' => 'Replacement detected.',
                'personas_detectadas' => [[
                    'nombre' => 'New Person',
                    'cargo' => 'Director',
                    'user_identity' => 'sensitive-user',
                ]],
                'model' => 'sensitive-model-in-analysis',
            ],
            'imagenes_cambio_json' => [[
                'path' => '/private/image.png',
                'mime_type' => 'image/png',
            ]],
        ]));

        AuthorityRemovalReview::create([
            'fuente_id' => $source->id,
            'origen' => 'pep_monitor',
            'linea_base_json' => [],
            'candidato_json' => [],
            'eventos_propuestos_json' => [],
            'evidencia_json' => ['secret' => 'sensitive-review-evidence'],
            'fingerprint' => str_repeat('a', 64),
            'estado' => 'confirmed',
            'decidido_por' => $actor->id,
            'evidencia_decision_json' => ['actor_email' => $actor->email],
            'cambio_confirmado_id' => $cambio->id,
        ]);

        $response = $this->actingAs($supervisor)
            ->get(route('pep.cambios.diagnostic-export'));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/x-ndjson; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=cambios-pep-diagnostic-sample.ndjson',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = $response->streamedContent();
        $record = json_decode(trim($body), true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'sample_stratum',
            'cambio_id',
            'feed_status',
            'fecha',
            'source',
            'lineas_nuevas',
            'lineas_quitadas',
            'diff_excerpt',
            'diff_truncated',
            'posibles_peps',
            'autoridades_eventos',
            'persona_removida',
            'persona_nueva',
            'cargo',
            'es_mae',
            'riesgo',
            'analisis',
            'personas_detectadas',
            'gemini_analyzed_at',
            'has_images',
            'image_count',
            'authority_review',
            'revisado',
        ], array_keys($record));
        $this->assertArrayNotHasKey('analysis_request_type', $record);
        $this->assertSame(CambioFeedStatus::Primary->value, $record['feed_status']);
        $this->assertSame('Replacement detected.', $record['analisis']);
        $this->assertSame([
            ['nombre' => 'New Person', 'cargo' => 'Director'],
        ], $record['personas_detectadas']);
        $this->assertSame(['id', 'nombre', 'organismo', 'pais', 'url'], array_keys($record['source']));
        $this->assertSame(['present' => true, 'status' => 'confirmed'], $record['authority_review']);
        $this->assertTrue($record['has_images']);
        $this->assertSame(1, $record['image_count']);

        foreach ([
            'sensitive-old-hash',
            'sensitive-new-hash',
            '/private/image.png',
            '/private/authority.json',
            'sensitive-event-evidence',
            'sensitive-review-evidence',
            'sensitive-user',
            'sensitive-model-in-analysis',
            $actor->email,
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $body);
        }

        $this->assertLessThanOrEqual(CambioDiagnosticExportService::MAX_RESPONSE_BYTES, strlen($body));
    }

    public function test_download_action_is_visible_only_to_authorized_users(): void
    {
        $supervisor = $this->userWithRole('supervisor');
        $operator = $this->userWithRole('operador');

        $this->actingAs($supervisor)
            ->get(route('pep.cambios'))
            ->assertOk()
            ->assertSee('Descargar muestra diagnóstica')
            ->assertSee(route('pep.cambios.diagnostic-export'), false);

        $this->actingAs($operator)
            ->get(route('pep.cambios'))
            ->assertOk()
            ->assertDontSee('Descargar muestra diagnóstica')
            ->assertDontSee('data-testid="cambios-diagnostic-export"', false);
    }

    private function userWithRole(string $role, bool $active = true): User
    {
        $user = User::factory()->create(['activo' => $active]);
        $user->assignRole($role);

        return $user;
    }
}
