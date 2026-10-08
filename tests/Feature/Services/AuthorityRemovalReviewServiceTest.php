<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\AuthorityRemovalReviewStatus;
use App\Enums\CambioFeedStatus;
use App\Exceptions\AuthorityRemovalReviewStale;
use App\Exceptions\Pep\InvalidAuthorityEventPayload;
use App\Jobs\ProcessAuthorityReviewAnalysis;
use App\Models\AuthorityRemovalReview;
use App\Models\AuthorityReviewAnalysisOutbox;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\AuthorityRemovalReviewService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
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
        $confirmedCambio = Cambio::query()->findOrFail($resolved->cambio_confirmado_id);
        $this->assertSame(CambioFeedStatus::Primary, $confirmedCambio->feed_status);
        $this->assertSame([
            'version' => 1,
            'events' => [[
                'type' => 'remocion',
                'old' => ['cargo' => 'Auditor', 'persona' => 'Luis'],
                'new' => null,
            ]],
        ], $confirmedCambio->autoridades_eventos_json);
        $this->assertTrue(Cambio::primaryFeed()->whereKey($confirmedCambio->id)->exists());
        $this->assertSame('simultaneous text change', $unrelatedCambio->fresh()->diff_texto);
        Queue::assertPushed(ProcessAuthorityReviewAnalysis::class, 1);
        $this->assertNotNull($review->fresh()->analisis_despachado_at);
        $this->assertDatabaseHas('authority_review_analysis_outbox', [
            'authority_removal_review_id' => $review->id,
            'cambio_id' => $resolved->cambio_confirmado_id,
            'idempotency_key' => "authority-removal-review:{$review->id}:cambio:{$resolved->cambio_confirmado_id}",
        ]);
        $this->assertDatabaseCount('authority_review_analysis_outbox', 1);
    }

    public function test_confirm_retry_repairs_dispatch_after_queue_failure(): void
    {
        [$review, , $actor] = $this->scenario();
        $service = app(AuthorityRemovalReviewService::class);
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Queue unavailable'));

        $service->confirm($review->id, $actor);

        $this->assertSame(AuthorityRemovalReviewStatus::Confirmed, $review->fresh()->estado);
        $this->assertNull($review->fresh()->analisis_despachado_at);
        $this->assertDatabaseCount('cambios', 2);
        $this->assertDatabaseCount('authority_review_analysis_outbox', 1);
        $this->assertNull(AuthorityReviewAnalysisOutbox::query()->firstOrFail()->dispatch_claim_token);

        Queue::fake();
        $this->artisan('authority-reviews:dispatch-analysis-outbox')->assertSuccessful();

        Queue::assertPushed(ProcessAuthorityReviewAnalysis::class, 1);
        $this->assertNotNull(AuthorityReviewAnalysisOutbox::query()->firstOrFail()->dispatched_at);
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
        $this->assertDatabaseCount('authority_review_analysis_outbox', 0);
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
            $this->assertDatabaseCount('authority_review_analysis_outbox', 0);
        }
    }

    public function test_confirm_rejects_invalid_structured_events_without_creating_cambio(): void
    {
        Queue::fake();
        [$review, $snapshot, $actor] = $this->scenario();
        $originalRoster = $snapshot->autoridades_json;
        $review->update([
            'eventos_propuestos_json' => [[
                'type' => 'remocion',
                'old' => null,
                'new' => null,
            ]],
        ]);

        try {
            app(AuthorityRemovalReviewService::class)->confirm($review->id, $actor);
            $this->fail('Expected invalid authority event payload exception.');
        } catch (InvalidAuthorityEventPayload) {
            $this->assertSame(AuthorityRemovalReviewStatus::Pending, $review->fresh()->estado);
            $this->assertSame($originalRoster, $snapshot->fresh()->autoridades_json);
            $this->assertDatabaseCount('cambios', 1);
            $this->assertDatabaseCount('authority_review_analysis_outbox', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_generic_revision_mismatch_blocks_both_decisions_without_mutation(): void
    {
        foreach (['confirm', 'reject'] as $decision) {
            [$review, $observed, $actor] = $this->genericScenario();
            $review->fuente->update(['authority_extraction_revision' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
            $this->assertGenericStale($review, $observed, $actor, $decision);
        }
    }

    public function test_generic_observed_snapshot_must_be_latest_and_match_review_column(): void
    {
        foreach (['confirm', 'reject'] as $decision) {
            [$review, $observed, $actor] = $this->genericScenario();
            $review->update(['snapshot_observado_id' => $review->snapshot_base_id]);
            $this->assertGenericStale($review, $observed, $actor, $decision);

            [$review, $observed, $actor] = $this->genericScenario();
            Snapshot::create([
                'fuente_id' => $review->fuente_id,
                'hash' => str_repeat('d', 64),
                'texto' => 'later',
                'metodo' => 'html_estatico',
                'fecha' => $observed->fecha->copy()->addSecond(),
            ]);
            $this->assertGenericStale($review, $observed, $actor, $decision);
        }
    }

    public function test_generic_decisions_lock_source_before_review_before_latest_snapshot(): void
    {
        foreach (['confirm', 'reject'] as $decision) {
            Queue::fake();
            [$review, , $actor] = $this->genericScenario();
            $locks = [];
            DB::listen(static function ($query) use (&$locks): void {
                $sql = strtolower($query->sql);
                if (str_contains($sql, 'for update')) {
                    $locks[] = $sql;
                }
            });
            app(AuthorityRemovalReviewService::class)->{$decision}($review->id, $actor);
            $locks = array_values(array_unique($locks));
            $this->assertCount(3, $locks, implode("\n", $locks));
            $this->assertStringContainsString('"fuentes"', $locks[0]);
            $this->assertStringContainsString('"revisiones_remocion_autoridades"', $locks[1]);
            $this->assertStringContainsString('"snapshots"', $locks[2]);
        }
    }

    public function test_generic_same_second_latest_snapshot_uses_id_tiebreaker_for_both_decisions(): void
    {
        $snapshotLocks = [];
        DB::listen(static function ($query) use (&$snapshotLocks): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, '"snapshots"') && str_contains($sql, 'for update')) {
                $snapshotLocks[] = $sql;
            }
        });
        foreach (['confirm', 'reject'] as $decision) {
            foreach ([false, true] as $refresh) {
                Queue::fake();
                [$review, $observed, $actor] = $this->genericScenario();
                $base = $review->snapshotBase;
                $base->update(['fecha' => $observed->fecha]);

                if ($refresh) {
                    $previous = $observed;
                    $observed = Snapshot::create([
                        'fuente_id' => $review->fuente_id,
                        'hash' => str_repeat('d', 64),
                        'texto' => 'refreshed observation',
                        'metodo' => 'html_estatico',
                        'authority_extraction_revision' => $previous->authority_extraction_revision,
                        'authority_extraction_config_fingerprint' => $previous->authority_extraction_config_fingerprint,
                        'autoridades_json' => $previous->autoridades_json,
                        'fecha' => $previous->fecha,
                    ]);
                    $review->update([
                        'snapshot_base_id' => $previous->id,
                        'snapshot_observado_id' => $observed->id,
                        'evidencia_json' => array_replace($review->evidencia_json, [
                            'snapshot_base_id' => $previous->id,
                            'snapshot_observado_id' => $observed->id,
                            'baseline_hash' => $previous->hash,
                            'candidate_hash' => $observed->hash,
                        ]),
                    ]);
                }

                $this->assertSame($review->snapshot_base_id, $review->evidencia_json['snapshot_base_id']);
                $this->assertSame($observed->id, $review->evidencia_json['snapshot_observado_id']);
                $snapshotLocks = [];
                $cambiosBefore = Cambio::query()->count();
                $resolved = app(AuthorityRemovalReviewService::class)->{$decision}($review->id, $actor);

                $this->assertCount(1, $snapshotLocks, "{$decision}, refresh=".(int) $refresh);
                $this->assertMatchesRegularExpression('/order by "fecha" desc, "id" desc/', $snapshotLocks[0], "{$decision}, refresh=".(int) $refresh);
                $this->assertSame($decision === 'confirm' ? AuthorityRemovalReviewStatus::Confirmed : AuthorityRemovalReviewStatus::Rejected, $resolved->estado);
                $this->assertSame($decision === 'confirm' ? $review->candidato_json : $review->linea_base_json, $observed->fresh()->autoridades_json);
                $this->assertSame($review->snapshot_base_id, $review->fresh()->snapshot_base_id);
                $this->assertSame($cambiosBefore + ($decision === 'confirm' ? 1 : 0), Cambio::query()->count());
            }
        }
    }

    public function test_generic_evidence_mutations_block_both_decisions_atomically(): void
    {
        $mutations = [
            'inactive' => static fn ($review, $observed) => $review->fuente->update(['activo' => false]),
            'unconfirmed' => static fn ($review, $observed) => $review->fuente->update(['authority_extraction_confirmed_revision' => null]),
            'url' => static fn ($review, $observed) => $review->fuente->update(['url' => 'https://example.org/changed/'.$review->fuente_id]),
            'extractor' => static fn ($review, $observed) => $review->fuente->update(['autoridades_extractor' => 'umsa_rectorado']),
            'missing evidence' => static fn ($review, $observed) => $review->update(['evidencia_json' => []]),
            'wrong JSON shape' => static fn ($review, $observed) => $review->update(['evidencia_json' => 'broken']),
            'malformed evidence' => static fn ($review, $observed) => $review->update(['evidencia_json' => ['extractor' => 'structured_css_v1']]),
            'evidence revision' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['source_revision' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'])]),
            'evidence url' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['source_url' => 'https://example.org/wrong'])]),
            'evidence extractor' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['extractor' => 'umsa_rectorado'])]),
            'evidence fingerprint' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['config_fingerprint' => str_repeat('d', 64)])]),
            'evidence base id' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['snapshot_base_id' => $observed->id])]),
            'base column id' => static fn ($review, $observed) => $review->update(['snapshot_base_id' => $observed->id]),
            'base hash' => static fn ($review, $observed) => $review->snapshotBase->update(['hash' => str_repeat('d', 64)]),
            'base source' => static fn ($review, $observed) => $review->snapshotBase->update(['fuente_id' => Fuente::factory()->create()->id]),
            'observed evidence id' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['snapshot_observado_id' => $review->snapshot_base_id])]),
            'observed revision' => static fn ($review, $observed) => $observed->update(['authority_extraction_revision' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']),
            'observed fingerprint' => static fn ($review, $observed) => $observed->update(['authority_extraction_config_fingerprint' => str_repeat('d', 64)]),
            'observed page hash' => static fn ($review, $observed) => $observed->update(['hash' => str_repeat('d', 64)]),
            'candidate hash' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['candidate_hash' => str_repeat('d', 64)])]),
            'invalid hash' => static fn ($review, $observed) => $review->update(['evidencia_json' => array_replace($review->evidencia_json, ['baseline_hash' => []])]),
            'malformed roster' => static fn ($review, $observed) => $observed->update(['autoridades_json' => ['invalid']]),
            'changed roster' => static fn ($review, $observed) => $observed->update(['autoridades_json' => [['cargo' => 'Other', 'persona' => 'Changed']]]),
            'malformed review roster' => static fn ($review, $observed) => $review->update(['linea_base_json' => ['invalid']]),
            'canonical fingerprint' => static fn ($review, $observed) => $review->update(['fingerprint' => str_repeat('d', 64)]),
        ];
        foreach (['confirm', 'reject'] as $decision) {
            foreach ($mutations as $label => $mutate) {
                [$review, $observed, $actor] = $this->genericScenario();
                $mutate($review, $observed);
                try {
                    $this->assertGenericStale($review, $observed, $actor, $decision);
                } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
                    throw new \PHPUnit\Framework\AssertionFailedError("{$decision} / {$label}: {$failure->getMessage()}", 0, $failure);
                }
            }
        }
    }

    public function test_generic_metadata_only_edit_is_allowed_and_terminal_retries_are_idempotent(): void
    {
        foreach (['confirm', 'reject'] as $decision) {
            Queue::fake();
            [$review, $observed, $actor] = $this->genericScenario();
            $review->fuente->update(['nombre' => 'Updated label']);
            $service = app(AuthorityRemovalReviewService::class);
            $resolved = $service->{$decision}($review->id, $actor);
            $count = Cambio::query()->count();
            $service->{$decision}($review->id, $actor);
            $this->assertSame($count, Cambio::query()->count());
            $this->assertSame($resolved->estado, $review->fresh()->estado);
            $this->assertSame($actor->id, $review->fresh()->decidido_por);
            $this->assertSame($decision === 'confirm' ? $review->candidato_json : $review->linea_base_json, $observed->fresh()->autoridades_json);
            $review->fuente->update(['authority_extraction_revision' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
            $service->{$decision}($review->id, $actor);
            $this->assertSame($count, Cambio::query()->count());
        }
    }

    public function test_confirmed_retry_repairs_outbox_after_source_changes_without_new_decision(): void
    {
        [$review, , $actor] = $this->genericScenario();
        $service = app(AuthorityRemovalReviewService::class);
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $resolved = $service->confirm($review->id, $actor);
        $this->assertNull($review->fresh()->analisis_despachado_at);
        $review->fuente->update(['authority_extraction_revision' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        Queue::fake();
        $service->confirm($review->id, $actor);
        $this->assertSame($resolved->cambio_confirmado_id, $review->fresh()->cambio_confirmado_id);
        $this->assertDatabaseCount('cambios', 2);
        $this->assertDatabaseCount('authority_review_analysis_outbox', 1);
        Queue::assertPushed(ProcessAuthorityReviewAnalysis::class, 1);
    }

    public function test_generic_evidence_is_not_downgraded_to_legacy_when_extractor_changes(): void
    {
        foreach (['confirm', 'reject'] as $decision) {
            [$review, $observed, $actor] = $this->genericScenario();
            $review->fuente->update(['autoridades_extractor' => 'umsa_rectorado']);
            $this->assertGenericStale($review, $observed, $actor, $decision);
        }
    }

    public function test_superseded_generic_review_cannot_be_decided(): void
    {
        foreach (['confirm', 'reject'] as $decision) {
            [$review, $observed, $actor] = $this->genericScenario();
            $review->update(['estado' => AuthorityRemovalReviewStatus::Superseded, 'lifecycle_key' => $review->id]);
            $this->assertGenericStale($review, $observed, $actor, $decision, AuthorityRemovalReviewStatus::Superseded);
        }
    }

    private function assertGenericStale(AuthorityRemovalReview $review, Snapshot $observed, User $actor, string $decision, AuthorityRemovalReviewStatus $expected = AuthorityRemovalReviewStatus::Pending): void
    {
        Queue::fake();
        $roster = $observed->fresh()->autoridades_json;
        $count = Cambio::query()->count();
        try {
            app(AuthorityRemovalReviewService::class)->{$decision}($review->id, $actor);
            $this->fail('Expected stale generic authority review.');
        } catch (AuthorityRemovalReviewStale) {
            $this->assertSame($expected, $review->fresh()->estado);
            $this->assertSame($roster, $observed->fresh()->autoridades_json);
            $this->assertSame($count, Cambio::query()->count());
            $this->assertDatabaseCount('authority_review_analysis_outbox', 0);
            Queue::assertNothingPushed();
        }
    }

    private function genericScenario(): array
    {
        [$review, , $actor, $baseline] = $this->scenario();
        $source = $review->fuente;
        $revision = '12345678-1234-4234-8234-123456789abc';
        $fingerprint = str_repeat('c', 64);
        $source->update([
            'activo' => true,
            'url' => "https://example.org/authorities/{$source->id}",
            'autoridades_extractor' => 'structured_css_v1',
            'authority_extraction_revision' => $revision,
            'authority_extraction_confirmed_revision' => $revision,
        ]);
        $base = $review->snapshotBase;
        $base->update([
            'authority_extraction_revision' => $revision,
            'authority_extraction_config_fingerprint' => $fingerprint,
            'autoridades_json' => $baseline,
            'fecha' => now()->subMinute(),
        ]);
        $observed = Snapshot::create([
            'fuente_id' => $source->id,
            'hash' => str_repeat('b', 64),
            'texto' => 'observed',
            'metodo' => 'html_estatico',
            'authority_extraction_revision' => $revision,
            'authority_extraction_config_fingerprint' => $fingerprint,
            'autoridades_json' => array_merge($baseline, [[
                '_authority_roster' => ['version' => 2, 'pending' => $review->candidato_json],
            ]]),
            'fecha' => now(),
        ]);
        $review->update([
            'snapshot_observado_id' => $observed->id,
            'evidencia_json' => [
                'version' => 1,
                'source_revision' => $revision,
                'config_fingerprint' => $fingerprint,
                'snapshot_base_id' => $base->id,
                'snapshot_observado_id' => $observed->id,
                'source_url' => $source->url,
                'extractor' => 'structured_css_v1',
                'baseline_hash' => $base->hash,
                'candidate_hash' => $observed->hash,
            ],
        ]);

        return [$review, $observed, $actor];
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
