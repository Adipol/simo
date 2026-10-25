<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

use App\Exceptions\PepDirectory\ReviewConflictException;
use App\Models\PepDirectoryEvent;
use App\Models\PepDirectoryObservation;
use App\Models\PepDirectoryReview;
use App\Models\PepDirectorySource;
use App\Models\User;
use App\Services\PepDirectory\DTOs\ReviewDecisionData;
use App\Services\PepDirectory\ReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\PepDirectoryReviewTestCase;

final class ReviewServiceTest extends PepDirectoryReviewTestCase
{
    private function actor(string $role = 'admin', string $permission = 'gestionar fuentes'): User
    {
        $name = 'synthetic-review-'.Str::uuid();
        $values = ['name' => $name, 'activo' => true];
        if ($this->db->getSchemaBuilder()->hasColumn('users', 'label')) {
            $values['label'] = $name;
        }
        $id = $this->db->table('users')->insertGetId($values);
        $actor = User::findOrFail($id);
        $roleModel = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $roleModel->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        $actor->assignRole($roleModel);

        return $actor;
    }

    private function event(): int
    {
        $source = 'synthetic-review-'.Str::uuid();
        $sourceId = $this->db->table('pep_directory_sources')->insertGetId(['identity' => $source]);
        $position = ['unit' => 'Synthetic unit', 'role' => 'Director', 'person' => 'Synthetic Ana', 'condition' => 'a.i.', 'evidence' => 'Synthetic original á'];
        $observations = [];
        foreach (['Synthetic Ana', 'Synthetic Beatriz'] as $index => $person) {
            $position['person'] = $person;
            $payload = ['source' => $source, 'revision' => 'synthetic-v1', 'observed_at' => '2026-10-18T12:00:0'.$index.'-04:00',
                'validity' => 'valid', 'positions' => [$position], 'evidence' => 'Synthetic original á '.$index];
            $observations[] = $this->db->table('pep_directory_observations')->insertGetId([
                'source_id' => $sourceId, 'submission_id' => 'synthetic-'.$index, 'capture_identity' => 'synthetic-capture-'.$index,
                'content_sha256' => hash('sha256', $payload['evidence']), 'provenance' => '{}', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'observed_at' => $payload['observed_at'], 'disposition' => $index === 0 ? 'baseline' : 'compared', 'reasons' => '[]',
            ]);
        }
        $before = $position;
        $before['person'] = 'Synthetic Ana';
        $payload = ['source' => $source, 'unit' => $position['unit'], 'role' => $position['role'], 'kind' => 'possible_replacement',
            'before' => $before, 'after' => $position, 'previous_observed_at' => '2026-10-18T12:00:00-04:00', 'detected_at' => '2026-10-18T12:00:01-04:00',
            'previous_evidence' => 'Synthetic original á 0', 'current_evidence' => 'Synthetic original á 1',
            'before_group' => [$before], 'after_group' => [$position], 'outgoing' => [$before], 'incoming' => [$position],
            'condition_changes' => [], 'effective_date' => null];
        $this->db->table('pep_directory_sources')->where('id', $sourceId)->update(['baseline_observation_id' => $observations[1], 'version' => 2]);

        return $this->db->table('pep_directory_events')->insertGetId(['source_id' => $sourceId, 'previous_observation_id' => $observations[0],
            'current_observation_id' => $observations[1], 'event_index' => 0, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    private function decide(int $event, User $actor, string $state, int $version): PepDirectoryReview
    {
        return (new ReviewService())->decide($event, $actor, ReviewDecisionData::fromArray([
            'state' => $state, 'expected_version' => $version, 'reason' => 'Synthetic evidence reviewed: á',
        ]));
    }

    private function stored(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $this->db->table($table)->orderBy('id')->get()->toArray();
        }

        return $rows;
    }

    public function test_confirm_discard_pending_and_same_state_preserve_complete_history_and_evidence(): void
    {
        $eventId = $this->event();
        $actor = $this->actor();
        $event = PepDirectoryEvent::findOrFail($eventId);
        $original = $event->getRawOriginal();
        $source = $event->source->getRawOriginal();
        $observations = $event->source->observations->map->getRawOriginal()->all();
        $prior = 'pending';
        $priorReviews = [];
        $auditSql = [];
        $this->db->listen(static function (QueryExecuted $query) use (&$auditSql): void {
            if (str_starts_with($query->sql, 'insert into "pep_directory_reviews"')) {
                $auditSql[] = $query->sql;
            }
        });
        foreach (['pending', 'confirmed', 'discarded', 'pending', 'confirmed'] as $index => $state) {
            $review = $this->decide($eventId, $actor, $state, $index);
            self::assertSame($index + 1, $review->version);
            self::assertSame($prior, $review->prior_state);
            self::assertSame($state, $review->new_state);
            self::assertSame('Synthetic evidence reviewed: á', $review->reason);
            self::assertSame('user:'.$actor->id, $review->actor_identity);
            self::assertSame(['id' => $actor->id, 'name' => $actor->name], $review->actor_snapshot);
            self::assertSame($actor->id, $review->user_id);
            self::assertNotNull($review->decided_at);
            self::assertSame($this->db->selectOne('SELECT decided_at::text AS value FROM pep_directory_reviews WHERE id = ?', [$review->id])->value, $review->getRawOriginal('decided_at'));
            self::assertSame($state, $event->fresh()->review_state);
            self::assertSame($index + 1, $event->fresh()->review_version);
            $history = $event->fresh()->reviews->map->getRawOriginal()->all();
            self::assertSame($priorReviews, array_slice($history, 0, $index), 'Earlier audit decisions must remain byte-identical.');
            $priorReviews = $history;
            $prior = $state;
        }
        self::assertCount(5, $auditSql);
        foreach ($auditSql as $sql) {
            foreach (['decided_at', 'created_at', 'updated_at', 'payload', 'effective_date'] as $protected) {
                self::assertStringNotContainsString($protected, $sql);
            }
        }
        $loaded = PepDirectoryEvent::withReviewEvidence()->findOrFail($eventId);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        self::assertCount(5, $loaded->reviews);
        foreach ($loaded->reviews as $review) {
            self::assertSame($actor->id, $review->user->id);
        }
        self::assertSame($observations[0]['payload'], $loaded->previousObservation->getRawOriginal('payload'));
        self::assertSame($observations[1]['payload'], $loaded->currentObservation->getRawOriginal('payload'));
        self::assertSame($source, $loaded->source->getRawOriginal());
        self::assertNull($loaded->payload['effective_date']);
        self::assertSame([], $this->db->getQueryLog(), 'Eager read seam must not issue N+1 queries.');
        $this->db->disableQueryLog();
        $updated = $loaded->getRawOriginal();
        unset($original['review_state'], $original['review_version'], $updated['review_state'], $updated['review_version']);
        self::assertSame($original, $updated);
        self::assertSame($observations, $loaded->source->observations->map->getRawOriginal()->all());
    }

    public function test_actual_supervisor_permission_allows_and_role_name_alone_does_not(): void
    {
        $event = $this->event();
        $supervisor = $this->actor('supervisor');
        self::assertSame('confirmed', $this->decide($event, $supervisor, 'confirmed', 0)->new_state);
        $supervisor->roles->first()->revokePermissionTo('gestionar fuentes');
        foreach (['pending', 'confirmed', 'discarded'] as $state) {
            $before = $this->stored();
            try {
                $this->decide($event, $supervisor, $state, 1);
                self::fail('Revoked role permission must not authorize re-review.');
            } catch (AuthorizationException $error) {
                self::assertSame('An active user with gestionar fuentes is required.', $error->getMessage());
            }
            self::assertEquals($before, $this->stored());
        }
    }

    public function test_stale_loaded_inactive_deleted_and_acknowledgement_only_actors_cannot_mutate_any_state(): void
    {
        foreach (['operator', 'inactive', 'deleted', 'direct-revoked'] as $case) {
            $actor = $this->actor('synthetic-'.$case, $case === 'operator' ? 'marcar revisado pep' : 'gestionar fuentes');
            $actor->load('roles.permissions', 'permissions');
            if ($case === 'inactive') {
                $this->db->table('users')->where('id', $actor->id)->update(['activo' => false]);
                self::assertTrue($actor->activo, 'Caller is deliberately stale.');
            } elseif ($case === 'deleted') {
                // Rolled back with this test; no pre-existing user is touched.
                $actor->delete();
            } elseif ($case === 'direct-revoked') {
                $actor->syncRoles([]);
                $actor->givePermissionTo('gestionar fuentes');
                self::assertTrue($actor->hasPermissionTo('gestionar fuentes'));
                $this->db->table('model_has_permissions')->where('model_id', $actor->id)->delete();
            }
            $event = $this->event();
            foreach (['pending', 'confirmed', 'discarded'] as $state) {
                $before = $this->stored();
                try {
                    $this->decide($event, $actor, $state, 0);
                    self::fail('Unauthorized '.$case.' mutation.');
                } catch (AuthorizationException $error) {
                    self::assertSame('An active user with gestionar fuentes is required.', $error->getMessage());
                }
                self::assertEquals($before, $this->stored());
            }
        }
    }

    public function test_dto_rejects_malformed_and_every_unknown_protected_field_without_writes(): void
    {
        $this->actor();
        $this->event();
        $valid = ['state' => 'confirmed', 'expected_version' => 0, 'reason' => 'Synthetic reason'];
        $invalid = [
            ['state', 'approved'], ['state', null], ['state', ['pending']],
            ['expected_version', -1], ['expected_version', '0'], ['expected_version', true], ['expected_version', 1.0], ['expected_version', PHP_INT_MAX],
            ['reason', '   '], ['reason', null], ['reason', []], ['reason', str_repeat('a', 10001)], ['reason', "bad\0reason"], ['reason', "\xFF"],
        ];
        foreach (['actor', 'actor_identity', 'actor_snapshot', 'user_id', 'decided_at', 'payload', 'evidence', 'effective_date',
            'event_id', 'version', 'prior_state', 'new_state', 'review_state', 'review_version', 'detected_at', 'received_at',
            'appointment_date', 'cessation_date', 'source_id', 'created_at', 'updated_at'] as $field) {
            $invalid[] = [$field, 'client supplied'];
        }
        foreach ($invalid as [$field, $value]) {
            $before = $this->stored();
            try {
                ReviewDecisionData::fromArray(array_replace($valid, [$field => $value]));
                self::fail('Accepted invalid '.$field);
            } catch (ValidationException $error) {
                $message = match ($field) {
                    'state' => 'State must be pending, confirmed or discarded.',
                    'expected_version' => 'Expected version must be a nonnegative integer below PHP_INT_MAX.',
                    'reason' => 'Reason must be nonblank UTF-8 text of at most 10000 characters without NUL bytes.',
                    default => 'This field cannot be supplied for a review decision.',
                };
                self::assertSame([$field => [$message]], $error->errors());
            }
            self::assertEquals($before, $this->stored());
        }
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            try {
                ReviewDecisionData::fromArray($missing);
                self::fail('Missing '.$field.' accepted.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey($field, $error->errors());
            }
        }
    }

    public function test_stale_version_fails_without_audit_drift_and_explicit_retry_uses_new_version(): void
    {
        $actor = $this->actor();
        $event = $this->event();
        $this->decide($event, $actor, 'confirmed', 0);
        $before = $this->stored();
        foreach ([0, 2] as $expectedVersion) {
            try {
                $this->decide($event, $actor, 'discarded', $expectedVersion);
                self::fail('Mismatching version accepted.');
            } catch (ReviewConflictException $error) {
                self::assertSame('The proposal has a newer review decision. Reload before deciding.', $error->getMessage());
                self::assertSame($expectedVersion, $error->expectedVersion);
                self::assertSame(1, $error->actualVersion);
            }
            self::assertEquals($before, $this->stored());
        }
        self::assertSame(2, $this->decide($event, $actor, 'pending', 1)->version);
        self::assertSame([1, 2], PepDirectoryEvent::findOrFail($event)->reviews->pluck('version')->all());
    }

    public function test_persisted_actor_snapshot_overrides_loaded_claims_and_holder_history_is_eager(): void
    {
        $actor = $this->actor('arbitrary-role-name');
        $name = $actor->name;
        $actor->name = 'Untrusted loaded name';
        $actor->activo = false;
        $event = $this->event();
        $review = $this->decide($event, $actor, 'confirmed', 0);
        self::assertSame(['id' => $actor->id, 'name' => $name], $review->actor_snapshot);
        $source = PepDirectorySource::withHolderHistory()->findOrFail($review->event->source_id);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        self::assertCount(2, $source->observations);
        self::assertSame($source->observations[1]->id, $source->baselineObservation->id);
        self::assertSame('2026-10-18T12:00:01-04:00', $source->baselineObservation->observed_at);
        self::assertSame('Synthetic original á 1', $source->baselineObservation->payload['evidence']);
        self::assertSame([], $this->db->getQueryLog());
        $this->db->disableQueryLog();
        $before = $this->stored();
        foreach ([0, PHP_INT_MAX] as $eventId) {
            try {
                $this->decide($eventId, $actor, 'pending', 0);
                self::fail('Invalid or missing proposal accepted.');
            } catch (ValidationException $error) {
                self::assertSame(['event_id' => ['A positive proposal identifier is required.']], $error->errors());
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
                self::assertSame(PepDirectoryEvent::class, $error->getModel());
            }
            self::assertEquals($before, $this->stored());
        }
    }

    public function test_fault_after_real_audit_insert_and_projection_update_rolls_back_entire_decision(): void
    {
        $actor = $this->actor();
        $event = $this->event();
        $before = $this->stored();
        $target = '';
        $this->db->listen(static function (QueryExecuted $query) use (&$target): void {
            if ($target !== '' && str_starts_with($query->sql, $target)) {
                throw new \RuntimeException('Synthetic post-write fault');
            }
        });
        foreach (['insert into "pep_directory_reviews"', 'update "pep_directory_events"'] as $target) {
            try {
                $this->decide($event, $actor, 'confirmed', 0);
                self::fail('Injected fault not reached.');
            } catch (\RuntimeException $error) {
                self::assertSame('Synthetic post-write fault', $error->getMessage());
            }
            self::assertEquals($before, $this->stored());
        }
    }

    public function test_deleted_live_user_preserves_server_snapshot_and_read_models_refuse_saves(): void
    {
        $actor = $this->actor();
        $event = $this->event();
        $review = $this->decide($event, $actor, 'confirmed', 0);
        $snapshot = $review->getRawOriginal();
        $actor->delete();
        $fresh = $review->fresh();
        self::assertNull($fresh->user_id);
        self::assertNull($fresh->user);
        unset($snapshot['user_id']);
        $actual = $fresh->getRawOriginal();
        unset($actual['user_id']);
        self::assertSame($snapshot, $actual);
        foreach ([$fresh, PepDirectoryEvent::findOrFail($event), PepDirectoryObservation::findOrFail($review->event->current_observation_id), PepDirectorySource::findOrFail($review->event->source_id)] as $model) {
            foreach (['save', 'delete'] as $method) {
                try {
                    $model->{$method}();
                    self::fail('Read model exposed '.$method);
                } catch (\LogicException $error) {
                    self::assertSame('Directory read models cannot be persisted.', $error->getMessage());
                }
            }
        }
    }

    public function test_concurrent_public_service_decisions_have_one_winner_observed_blocking_and_no_drift(): void
    {
        self::assertTrue(class_exists(ReviewService::class), 'Public review service is required.');
        if (! function_exists('proc_open')) {
            self::fail('Independent-process concurrency capability unavailable.');
        }
        // Discard this test's uncommitted setup, then introduce only owned auth metadata.
        $this->db->rollBack();
        $this->db->beginTransaction();
        $this->authorization->up();
        $actor = $this->actor('synthetic-concurrent');
        $eventId = $this->event();
        $this->db->commit();
        $this->committedAuthorization = true;
        echo 'COMMITTED SYNTHETIC FIXTURE '.json_encode(['event_id' => $eventId, 'user_id' => $actor->id,
            'source_identity' => PepDirectoryEvent::findOrFail($eventId)->source->identity,
            'authorization_additions' => $this->authorization->introducedMetadata(),
            'roles' => $this->db->table('roles')->get()->toArray(), 'permissions' => $this->db->table('permissions')->get()->toArray(),
            'model_has_roles' => $this->db->table('model_has_roles')->get()->toArray(),
            'role_has_permissions' => $this->db->table('role_has_permissions')->get()->toArray()], JSON_THROW_ON_ERROR).PHP_EOL;
        $originalEvent = PepDirectoryEvent::withReviewEvidence()->findOrFail($eventId);
        $before = $originalEvent->getRawOriginal();
        $evidenceBefore = [$originalEvent->source->getRawOriginal(), $originalEvent->previousObservation->getRawOriginal(), $originalEvent->currentObservation->getRawOriginal()];
        $workers = [];
        try {
            $workers[] = $this->worker('holder', $eventId, $actor->id);
            $holder = $this->message($workers[0][1][1]);
            self::assertSame('locked', $holder['stage']);
            $workers[] = $this->worker('contender', $eventId, $actor->id);
            $contender = $this->message($workers[1][1][1]);
            self::assertSame('ready', $contender['stage']);
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $blocked = $this->db->selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS blocked', [$holder['pid'], $contender['pid']])->blocked;
            } while (! $blocked && hrtime(true) < $deadline);
            self::assertTrue($blocked, 'PostgreSQL must demonstrate contender blocked by the owned holder.');
            echo 'OBSERVED BLOCKING '.json_encode([$contender['pid'], $holder['pid']], JSON_THROW_ON_ERROR).PHP_EOL;
            fwrite($workers[0][1][0], "continue\n");
            $won = $this->message($workers[0][1][1]);
            $lost = $this->message($workers[1][1][1]);
            self::assertSame(['outcome' => 'review', 'version' => 1], $won);
            self::assertSame(['outcome' => 'conflict', 'expected' => 0, 'actual' => 1], $lost);
        } finally {
            $exits = [];
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                $exits[] = proc_close($process);
            }
            self::assertSame(array_fill(0, count($workers), 0), $exits, 'Concurrency helper failed.');
        }
        $event = PepDirectoryEvent::withReviewEvidence()->findOrFail($eventId);
        self::assertSame('confirmed', $event->review_state);
        self::assertSame(1, $event->review_version);
        self::assertCount(1, $event->reviews);
        self::assertSame('pending', $event->reviews[0]->prior_state);
        self::assertSame('confirmed', $event->reviews[0]->new_state);
        self::assertSame(1, $event->reviews[0]->version);
        self::assertSame($actor->id, $event->reviews[0]->user_id);
        self::assertSame('Synthetic concurrent evidence review', $event->reviews[0]->reason);
        self::assertSame('user:'.$actor->id, $event->reviews[0]->actor_identity);
        self::assertSame(['id' => $actor->id, 'name' => $actor->name], $event->reviews[0]->actor_snapshot);
        self::assertNotNull($event->reviews[0]->decided_at);
        $auditBefore = $event->reviews[0]->getRawOriginal();
        $after = $event->getRawOriginal();
        unset($before['review_state'], $before['review_version'], $after['review_state'], $after['review_version']);
        self::assertSame($before, $after);
        $retry = $this->decide($eventId, $actor, 'pending', 1);
        self::assertSame(2, $retry->version);
        self::assertSame('confirmed', $retry->prior_state);
        self::assertSame('pending', $retry->new_state);
        $final = PepDirectoryEvent::withReviewEvidence()->findOrFail($eventId);
        self::assertSame('pending', $final->review_state);
        self::assertSame(2, $final->review_version);
        self::assertSame([1, 2], $final->reviews->pluck('version')->all());
        self::assertSame($auditBefore, $final->reviews[0]->getRawOriginal());
        self::assertSame($evidenceBefore, [$final->source->getRawOriginal(), $final->previousObservation->getRawOriginal(), $final->currentObservation->getRawOriginal()]);
    }

    /** @return array{resource, array<int, resource>} */
    private function worker(string $mode, int $event, int $actor): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '/work/tests/Integration/PepDirectory/review_concurrency.php', $mode, (string) $event, (string) $actor],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, '/work');
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start isolated concurrency process.');
        }

        return [$process, $pipes];
    }

    /** @param resource $pipe */
    private function message($pipe): array
    {
        $read = [$pipe];
        $write = $except = [];
        if (stream_select($read, $write, $except, 3) !== 1) {
            throw new \RuntimeException('Bounded concurrency synchronization failed.');
        }
        $line = fgets($pipe);
        if ($line === false) {
            throw new \RuntimeException('Concurrency helper closed without a message.');
        }

        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
}
