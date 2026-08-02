<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuthorityRemovalReviewStatus;
use App\Exceptions\AuthorityRemovalReviewStale;
use App\Models\AuthorityRemovalReview;
use App\Models\AuthorityReviewAnalysisOutbox;
use App\Models\Cambio;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AuthorityRemovalReviewService
{
    public function __construct(
        private readonly AuthorityReviewAnalysisOutboxService $outbox,
    ) {}

    public function confirm(int $reviewId, User $actor, array $evidence = []): AuthorityRemovalReview
    {
        return DB::transaction(function () use ($reviewId, $actor, $evidence): AuthorityRemovalReview {
            $review = AuthorityRemovalReview::query()->lockForUpdate()->findOrFail($reviewId);
            if ($review->estado === AuthorityRemovalReviewStatus::Confirmed) {
                $this->dispatchAnalysisAfterCommit($review);

                return $review;
            }
            $this->ensurePending($review);

            $snapshot = Snapshot::query()
                ->where('fuente_id', $review->fuente_id)
                ->latest('fecha')
                ->lockForUpdate()
                ->firstOrFail();
            $trustedRoster = $this->withoutPendingMarker($snapshot->autoridades_json ?? []);
            $expectedFingerprint = self::fingerprint(
                (int) $review->fuente_id,
                $review->linea_base_json,
                $review->candidato_json,
            );

            if ($trustedRoster !== $review->linea_base_json || ! hash_equals($expectedFingerprint, $review->fingerprint)) {
                throw new AuthorityRemovalReviewStale('The trusted authority baseline or review fingerprint changed.');
            }

            $cambio = Cambio::withoutEvents(static fn (): Cambio => Cambio::create([
                'fuente_id' => $review->fuente_id,
                'hash_anterior' => $review->evidencia_json['baseline_hash'] ?? $snapshot->hash,
                'hash_nuevo' => $review->evidencia_json['candidate_hash'] ?? $snapshot->hash,
                'lineas_quitadas' => 0,
                'lineas_nuevas' => 0,
                'diff_texto' => '',
                'posibles_peps' => '',
                'autoridades_eventos_json' => [
                    'version' => 1,
                    'events' => $review->eventos_propuestos_json,
                ],
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
            $review = AuthorityRemovalReview::query()->lockForUpdate()->findOrFail($reviewId);
            if ($review->estado === AuthorityRemovalReviewStatus::Rejected) {
                return $review;
            }
            $this->ensurePending($review);

            $snapshot = Snapshot::query()
                ->where('fuente_id', $review->fuente_id)
                ->latest('fecha')
                ->lockForUpdate()
                ->firstOrFail();
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
