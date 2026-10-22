<?php

declare(strict_types=1);

namespace App\Services\PepDirectory;

use App\Exceptions\PepDirectory\ReviewConflictException;
use App\Models\PepDirectoryEvent;
use App\Models\PepDirectoryReview;
use App\Models\User;
use App\Services\PepDirectory\DTOs\ReviewDecisionData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class ReviewService
{
    /** Confirmation reviews a proposal; it never establishes legal tenure or an effective date. */
    public function decide(int $eventId, User $actor, ReviewDecisionData $decision): PepDirectoryReview
    {
        if ($eventId < 1) {
            throw ValidationException::withMessages(['event_id' => ['A positive proposal identifier is required.']]);
        }
        $connection = (new PepDirectoryEvent())->getConnection();

        return $connection->transaction(function () use ($connection, $eventId, $actor, $decision): PepDirectoryReview {
            // Lock before reading the projection. Concurrent callers see the committed winner's version.
            $event = PepDirectoryEvent::on($connection->getName())->lockForUpdate()->findOrFail($eventId);
            // Do not refresh/mutate the caller object or trust its loaded relations/activo/connection.
            $currentActor = User::on($connection->getName())->lockForUpdate()->find($actor->getKey());
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            if ($currentActor === null || ! $currentActor->activo || ! $currentActor->checkPermissionTo('gestionar fuentes')) {
                throw new AuthorizationException('An active user with gestionar fuentes is required.');
            }
            if ($event->review_version !== $decision->expectedVersion) {
                throw new ReviewConflictException($decision->expectedVersion, $event->review_version);
            }
            $version = $event->review_version + 1;
            // Deliberately no decided_at: PostgreSQL supplies its server default. No model save API.
            $id = PepDirectoryReview::on($connection->getName())->insertGetId([
                'event_id' => $event->id, 'version' => $version, 'user_id' => $currentActor->id,
                'actor_identity' => 'user:'.$currentActor->id,
                'actor_snapshot' => json_encode(['id' => $currentActor->id, 'name' => $currentActor->name], JSON_THROW_ON_ERROR),
                'reason' => $decision->reason, 'prior_state' => $event->review_state, 'new_state' => $decision->state,
            ]);
            PepDirectoryEvent::on($connection->getName())->whereKey($event->id)->update([
                'review_state' => $decision->state, 'review_version' => $version,
            ]);

            return PepDirectoryReview::on($connection->getName())->findOrFail($id);
        });
    }
}
