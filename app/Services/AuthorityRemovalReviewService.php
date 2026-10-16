<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuthorityRemovalReviewStatus;
use App\Enums\CambioFeedStatus;
use App\Exceptions\AuthorityRemovalReviewStale;
use App\Models\AuthorityRemovalReview;
use App\Models\AuthorityReviewAnalysisOutbox;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Pep\AuthorityEventFeedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AuthorityRemovalReviewService
{
    public function __construct(
        private readonly AuthorityReviewAnalysisOutboxService $outbox,
        private readonly AuthorityEventFeedService $feed,
    ) {}

    public function confirm(int $reviewId, User $actor, array $evidence = []): AuthorityRemovalReview
    {
        return DB::transaction(function () use ($reviewId, $actor, $evidence): AuthorityRemovalReview {
            [$source, $review] = $this->lockSourceAndReview($reviewId);
            if ($review->estado === AuthorityRemovalReviewStatus::Confirmed) {
                $this->dispatchAnalysisAfterCommit($review);

                return $review;
            }
            $this->ensurePending($review);

            $snapshot = Snapshot::query()
                ->where('fuente_id', $review->fuente_id)
                ->latest('fecha')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertGenericEvidence($source, $review, $snapshot);
            $trustedRoster = $this->withoutPendingMarker($snapshot->autoridades_json ?? []);
            $expectedFingerprint = self::fingerprint(
                (int) $review->fuente_id,
                $review->linea_base_json,
                $review->candidato_json,
            );

            if ($trustedRoster !== $review->linea_base_json || ! hash_equals($expectedFingerprint, $review->fingerprint)) {
                throw new AuthorityRemovalReviewStale('The trusted authority baseline or review fingerprint changed.');
            }

            $authorityPayload = $this->feed->assertPrimaryEligible([
                'version' => 1,
                'events' => $review->eventos_propuestos_json,
            ]);

            $cambio = Cambio::withoutEvents(static fn (): Cambio => Cambio::create([
                'fuente_id' => $review->fuente_id,
                'hash_anterior' => $review->evidencia_json['baseline_hash'] ?? $snapshot->hash,
                'hash_nuevo' => $review->evidencia_json['candidate_hash'] ?? $snapshot->hash,
                'lineas_quitadas' => 0,
                'lineas_nuevas' => 0,
                'diff_texto' => '',
                'posibles_peps' => '',
                'autoridades_eventos_json' => $authorityPayload->toArray(),
                'feed_status' => CambioFeedStatus::Primary,
            ]));

            $snapshot->update(['autoridades_json' => $review->candidato_json]);
            $review->update([
                'estado' => AuthorityRemovalReviewStatus::Confirmed,
                'decidido_por' => $actor->id,
                'decidido_at' => now(),
                'evidencia_decision_json' => ['version' => 1, 'evidence' => $evidence],
                'cambio_confirmado_id' => $cambio->id,
            ]);

            $outboxEvent = AuthorityReviewAnalysisOutbox::query()->firstOrCreate(
                ['authority_removal_review_id' => $review->id],
                [
                    'cambio_id' => $cambio->id,
                    'idempotency_key' => "authority-removal-review:{$review->id}:cambio:{$cambio->id}",
                ],
            );

            $this->dispatchAnalysisAfterCommit($review, $outboxEvent);

            return $review->fresh(['fuente', 'decididoPor', 'cambioConfirmado']);
        });
    }

    public function reject(int $reviewId, User $actor, array $evidence = []): AuthorityRemovalReview
    {
        return DB::transaction(function () use ($reviewId, $actor, $evidence): AuthorityRemovalReview {
            [$source, $review] = $this->lockSourceAndReview($reviewId);
            if ($review->estado === AuthorityRemovalReviewStatus::Rejected) {
                return $review;
            }
            $this->ensurePending($review);

            $snapshot = Snapshot::query()
                ->where('fuente_id', $review->fuente_id)
                ->latest('fecha')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertGenericEvidence($source, $review, $snapshot);
            $expectedFingerprint = self::fingerprint(
                (int) $review->fuente_id,
                $review->linea_base_json,
                $review->candidato_json,
            );
            if ($this->withoutPendingMarker($snapshot->autoridades_json ?? []) !== $review->linea_base_json
                || ! hash_equals($expectedFingerprint, $review->fingerprint)) {
                throw new AuthorityRemovalReviewStale('The trusted authority baseline changed.');
            }

            $snapshot->update(['autoridades_json' => $review->linea_base_json]);
            $review->update([
                'estado' => AuthorityRemovalReviewStatus::Rejected,
                'decidido_por' => $actor->id,
                'decidido_at' => now(),
                'evidencia_decision_json' => ['version' => 1, 'evidence' => $evidence],
            ]);

            return $review->fresh(['fuente', 'decididoPor']);
        });
    }

    public static function fingerprint(int $fuenteId, array $baseline, array $candidate): string
    {
        $payload = self::sortKeys([
            'version' => 1,
            'fuente_id' => $fuenteId,
            'baseline' => $baseline,
            'candidate' => $candidate,
        ]);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{Fuente, AuthorityRemovalReview} */
    private function lockSourceAndReview(int $reviewId): array
    {
        // The unlocked lookup only discovers the lock key; the locked review remains authoritative.
        $sourceId = AuthorityRemovalReview::query()->whereKey($reviewId)->value('fuente_id');
        if ($sourceId === null) {
            AuthorityRemovalReview::query()->findOrFail($reviewId);
        }
        $source = Fuente::query()->lockForUpdate()->findOrFail($sourceId);
        $review = AuthorityRemovalReview::query()->lockForUpdate()->findOrFail($reviewId);
        if ((int) $review->fuente_id !== (int) $source->id) {
            throw new AuthorityRemovalReviewStale('The authority review source changed.');
        }

        return [$source, $review];
    }

    private function assertGenericEvidence(Fuente $source, AuthorityRemovalReview $review, Snapshot $observed): void
    {
        $evidence = $review->evidencia_json;
        if ($source->autoridades_extractor !== 'structured_css_v1'
            && (! is_array($evidence) || ($evidence['extractor'] ?? null) !== 'structured_css_v1')) {
            return;
        }

        if (! is_array($evidence) || ! is_array($review->linea_base_json)
            || ! is_array($review->candidato_json) || ! is_string($review->fingerprint)
            || ! self::validHash($review->fingerprint)
            || ! is_array($observed->autoridades_json)
            || ! array_is_list($observed->autoridades_json)
            || ! array_is_list($review->linea_base_json)
            || ! array_is_list($review->candidato_json)
            || count(array_filter($observed->autoridades_json, 'is_array')) !== count($observed->autoridades_json)) {
            throw new AuthorityRemovalReviewStale('The generic authority roster or review evidence is malformed.');
        }

        $revision = $source->authority_extraction_revision;
        $baseId = $evidence['snapshot_base_id'] ?? null;
        $observedId = $evidence['snapshot_observado_id'] ?? null;
        $fingerprint = $evidence['config_fingerprint'] ?? null;
        $baselineHash = $evidence['baseline_hash'] ?? null;
        $candidateHash = $evidence['candidate_hash'] ?? null;
        if (! is_array($evidence)
            || ! $source->activo
            || $source->autoridades_extractor !== 'structured_css_v1'
            || ! is_string($revision) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $revision)
            || $source->authority_extraction_confirmed_revision !== $revision
            || ($evidence['source_revision'] ?? null) !== $revision
            || ($evidence['source_url'] ?? null) !== $source->url
            || ($evidence['extractor'] ?? null) !== $source->autoridades_extractor
            || ! is_int($baseId) || ! is_int($observedId)
            || $baseId !== (int) $review->snapshot_base_id
            || $observedId !== (int) $review->snapshot_observado_id
            || $observedId !== (int) $observed->id
            || ! self::validHash($fingerprint) || ! self::validHash($baselineHash) || ! self::validHash($candidateHash)
            || $observed->authority_extraction_revision !== $revision
            || $observed->authority_extraction_config_fingerprint !== $fingerprint
            || $observed->hash !== $candidateHash) {
            throw new AuthorityRemovalReviewStale('The generic authority review evidence is stale or incomplete.');
        }

        $base = Snapshot::query()->find($baseId);
        if ($base === null || (int) $base->fuente_id !== (int) $source->id || $base->hash !== $baselineHash) {
            throw new AuthorityRemovalReviewStale('The generic authority baseline evidence changed.');
        }
    }

    private static function validHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/i', $value) === 1;
    }

    private function ensurePending(AuthorityRemovalReview $review): void
    {
        if ($review->estado !== AuthorityRemovalReviewStatus::Pending) {
            throw new AuthorityRemovalReviewStale('This authority removal review is already resolved.');
        }
    }

    private function dispatchAnalysisAfterCommit(AuthorityRemovalReview $review, ?AuthorityReviewAnalysisOutbox $event = null): void
    {
        $event ??= AuthorityReviewAnalysisOutbox::query()
            ->where('authority_removal_review_id', $review->id)
            ->first();

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        DB::afterCommit(function () use ($review, $event): void {
            try {
                if ($this->outbox->dispatch($event->id)) {
                    AuthorityRemovalReview::query()
                        ->whereKey($review->id)
                        ->whereNull('analisis_despachado_at')
                        ->update(['analisis_despachado_at' => now()]);
                }
            } catch (Throwable $exception) {
                Log::warning('Authority review analysis remains pending in outbox.', [
                    'outbox_event_id' => $event->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function withoutPendingMarker(array $roster): array
    {
        return array_values(array_filter(
            $roster,
            static fn (array $item): bool => ! array_key_exists('_authority_roster', $item),
        ));
    }

    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
