<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\AuthorityRemovalReviewStatus;
use App\Exceptions\AuthorityRemovalReviewStale;
use App\Jobs\AnalizarCambioConPro;
use App\Models\AuthorityRemovalReview;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\AuthorityRemovalReviewService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class AuthorityRemovalReviewServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_confirm_promotes_baseline_creates_one_cambio_and_dispatches_after_commit(): void
    {
        Queue::fake();
        [$review, $snapshot, $actor, , $unrelatedCambio] = $this->scenario();
        $service = app(AuthorityRemovalReviewService::class);

        $resolved = $service->confirm($review->id, $actor, ['reason' => 'verified']);
        $service->confirm($review->id, $actor, ['reason' => 'duplicate click']);

        $this->assertSame(AuthorityRemovalReviewStatus::Confirmed, $resolved->estado);
        $this->assertSame($actor->id, $resolved->decidido_por);
        $this->assertNotNull($resolved->decidido_at);
        $this->assertSame([['cargo' => 'Director', 'persona' => 'Ana']], $snapshot->fresh()->autoridades_json);
        $this->assertDatabaseCount('cambios', 2);
        $this->assertDatabaseHas('cambios', ['id' => $resolved->cambio_confirmado_id]);
        $this->assertSame('simultaneous text change', $unrelatedCambio->fresh()->diff_texto);
        Queue::assertPushed(AnalizarCambioConPro::class, 1);
        $this->assertNotNull($review->fresh()->analisis_despachado_at);
    }

    public function test_confirm_retry_repairs_dispatch_after_queue_failure(): void
    {
        [$review, , $actor] = $this->scenario();
        $service = app(AuthorityRemovalReviewService::class);
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Queue unavailable'));

        try {
            $service->confirm($review->id, $actor);
            $this->fail('Expected queue dispatch failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        }

        $this->assertSame(AuthorityRemovalReviewStatus::Confirmed, $review->fresh()->estado);
        $this->assertNull($review->fresh()->analisis_despachado_at);
        $this->assertDatabaseCount('cambios', 2);

        Queue::fake();
        $service->confirm($review->id, $actor);

        Queue::assertPushed(AnalizarCambioConPro::class, 1);
        $this->assertNotNull($review->fresh()->analisis_despachado_at);
        $this->assertDatabaseCount('cambios', 2);
    }

    public function test_reject_preserves_baseline_and_creates_no_cambio(): void
    {
        Queue::fake();
        [$review, $snapshot, $actor, $baseline, $unrelatedCambio] = $this->scenario();

        $resolved = app(AuthorityRemovalReviewService::class)->reject($review->id, $actor, ['reason' => 'source incomplete']);

        $this->assertSame(AuthorityRemovalReviewStatus::Rejected, $resolved->estado);
        $this->assertSame($baseline, $snapshot->fresh()->autoridades_json);
        $this->assertDatabaseCount('cambios', 1);
        $this->assertSame('simultaneous text change', $unrelatedCambio->fresh()->diff_texto);
        Queue::assertNothingPushed();
    }

    public function test_confirm_rejects_stale_baseline_atomically(): void
    {
        Queue::fake();
        [$review, $snapshot, $actor] = $this->scenario();
        $snapshot->update(['autoridades_json' => [['cargo' => 'Other', 'persona' => 'Changed']]]);

        try {
            app(AuthorityRemovalReviewService::class)->confirm($review->id, $actor);
            $this->fail('Expected stale review exception.');
        } catch (AuthorityRemovalReviewStale) {
            $this->assertDatabaseCount('cambios', 1);
            $this->assertSame(AuthorityRemovalReviewStatus::Pending, $review->fresh()->estado);
            Queue::assertNothingPushed();
        }
    }

    private function scenario(): array
    {
        $actor = User::factory()->create(['activo' => true]);
        $fuente = Fuente::factory()->create();
        $baseline = [
            ['cargo' => 'Director', 'persona' => 'Ana'],
            ['cargo' => 'Auditor', 'persona' => 'Luis'],
        ];
        $candidate = [['cargo' => 'Director', 'persona' => 'Ana']];
        $snapshot = Snapshot::create([
            'fuente_id' => $fuente->id,
            'hash' => str_repeat('a', 64),
            'texto' => 'content',
            'metodo' => 'html_estatico',
            'autoridades_json' => array_merge($baseline, [[
                '_authority_roster' => ['version' => 2, 'pending' => $candidate],
            ]]),
            'fecha' => now(),
        ]);
        $review = AuthorityRemovalReview::create([
            'fuente_id' => $fuente->id,
            'snapshot_base_id' => $snapshot->id,
            'origen' => 'pep_monitor',
            'version_esquema' => 1,
            'linea_base_json' => $baseline,
            'candidato_json' => $candidate,
            'eventos_propuestos_json' => [[
                'type' => 'remocion',
                'old' => $baseline[1],
                'new' => null,
            ]],
            'evidencia_json' => ['version' => 1, 'baseline_hash' => str_repeat('a', 64)],
            'fingerprint' => AuthorityRemovalReviewService::fingerprint($fuente->id, $baseline, $candidate),
            'estado' => AuthorityRemovalReviewStatus::Pending,
        ]);
        $unrelatedCambio = Cambio::withoutEvents(static fn (): Cambio => Cambio::create([
            'fuente_id' => $fuente->id,
            'hash_anterior' => str_repeat('a', 64),
            'hash_nuevo' => str_repeat('b', 64),
            'diff_texto' => 'simultaneous text change',
        ]));

        return [$review, $snapshot, $actor, $baseline, $unrelatedCambio];
    }
}
