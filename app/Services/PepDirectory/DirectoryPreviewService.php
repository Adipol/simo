<?php

declare(strict_types=1);

namespace App\Services\PepDirectory;

use App\Exceptions\PepDirectory\ConfigurationConflictException;
use App\Models\{Fuente, PepDirectorySource, PepDirectoryConfiguration, PepDirectoryCapture, PepDirectoryPreview, PepDirectoryConfigurationDecision, User};
use App\Services\PepDirectory\DTOs\{ConfigurationDecisionData, DirectoryMappingData, ServerCaptureData};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** Dormant internal lifecycle. No transport adapter, approval or runtime eligibility authority. */
final class DirectoryPreviewService
{
    public function __construct(private readonly PreviewCompiler $compiler) {}

    public function register(int $fuenteId, User $actor): PepDirectorySource
    {
        $db = (new PepDirectorySource())->getConnection();
        return $db->transaction(function () use ($db, $fuenteId, $actor): PepDirectorySource {
            $name = $db->getName();
            $fuente = Fuente::on($name)->lockForUpdate()->findOrFail($fuenteId);
            $source = PepDirectorySource::on($name)->where('fuente_id', $fuente->id)->where('configurable', true)->lockForUpdate()->first();
            $currentActor = $this->actor($name, $actor);
            if ($source !== null) {
                return $source;
            }
            $id = PepDirectorySource::on($name)->insertGetId(['identity' => (string) Str::uuid(), 'fuente_id' => $fuente->id, 'configurable' => true]);
            $source = PepDirectorySource::on($name)->findOrFail($id);
            $this->receipt($source, $currentActor, 'register', 'Human registration', []);
            return $source->fresh();
        });
    }

    public function recordCapture(int $sourceId, ServerCaptureData $capture, User $actor, ConfigurationDecisionData $decision): PepDirectoryCapture
    {
        $db = (new PepDirectorySource())->getConnection();
        return $db->transaction(function () use ($db, $sourceId, $capture, $actor, $decision): PepDirectoryCapture {
            [$fuente, $source, $currentActor] = $this->locked($db->getName(), $sourceId, $actor, $decision);
            $this->require($capture->requestedUrl === $fuente->url && $capture->finalUrl === $fuente->url);
            $id = PepDirectoryCapture::on($db->getName())->insertGetId([
                'source_id' => $sourceId, 'body_bytes' => '\\x'.bin2hex($capture->body), 'content_sha256' => hash('sha256', $capture->body),
                'metadata' => $this->json($capture->metadata), 'captured_at' => $capture->metadata['observed_at'],
                'provenance' => $this->json($capture->provenance + ['requested_url' => $capture->requestedUrl, 'final_url' => $capture->finalUrl, 'actor_identity' => 'user:'.$currentActor->id, 'actor_snapshot' => $this->actorSnapshot($currentActor)]),
            ]);
            // Appending evidence fences stale work but leaves an existing approval intact.
            $this->receipt($source, $currentActor, 'capture', 'capture:'.$id.' '.$decision->reason, []);
            return PepDirectoryCapture::on($db->getName())->findOrFail($id);
        });
    }

    public function publishDraftPreview(int $sourceId, int $captureId, DirectoryMappingData $mapping, User $actor, ConfigurationDecisionData $decision): PepDirectoryPreview
    {
        $db = (new PepDirectorySource())->getConnection();
        $name = $db->getName();
        $snapshot = $db->transaction(function () use ($name, $sourceId, $captureId, $actor, $decision): array {
            [$fuente, $source] = $this->locked($name, $sourceId, $actor, $decision);
            return $this->snapshot($name, $fuente, $source, $captureId);
        });
        $declaration = ['source' => $snapshot['source']['identity'], 'url' => $snapshot['fuente']['url']] + $mapping->mapping;
        // Only the service-owned transaction has ended; a caller/test transaction may still exist.
        $compiled = $this->compiler->compile($declaration, $snapshot['capture']['body_bytes'], $snapshot['metadata']);
        return $db->transaction(function () use ($name, $sourceId, $captureId, $actor, $decision, $snapshot, $declaration, $compiled): PepDirectoryPreview {
            [$fuente, $source, $currentActor] = $this->locked($name, $sourceId, $actor, $decision);
            try {
                $current = $this->snapshot($name, $fuente, $source, $captureId);
            } catch (ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
            }
            if ($snapshot !== $current) {
                throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
            }
            $config = PepDirectoryConfiguration::on($name)->where('source_id', $sourceId)->where('revision', $compiled->revision)->first();
            if ($config !== null) {
                // CompiledPreviewData already checks the declaration against canonical identity.
                // Preserve the original declaration spelling; field order/default scope are not identity.
                // A matching revision with different actual bytes is corruption, never deduplication.
                $this->require($this->row($config)['canonical_bytes'] === $compiled->canonicalBytes && $config->binding_url === $fuente->url && $config->directory_url === $fuente->url);
                $configId = $config->id;
            } else {
                $configId = PepDirectoryConfiguration::on($name)->insertGetId([
                    'source_id' => $sourceId, 'declaration' => $this->json($declaration), 'canonical_bytes' => '\\x'.bin2hex($compiled->canonicalBytes),
                    'revision' => $compiled->revision, 'binding_url' => $fuente->url, 'directory_url' => $fuente->url,
                    'creator_identity' => 'user:'.$currentActor->id, 'creator_snapshot' => $this->json($this->actorSnapshot($currentActor)),
                ]);
            }
            $id = PepDirectoryPreview::on($name)->insertGetId([
                'source_id' => $sourceId, 'configuration_id' => $configId, 'capture_id' => $captureId,
                'result_bytes' => '\\x'.bin2hex($compiled->resultBytes), 'result_sha256' => $compiled->resultSha256, 'wire_payload' => $this->json($compiled->wire),
                'validity' => $compiled->validity, 'qualification' => $this->json(['state' => count($compiled->wire['positions']) === 0 ? 'unapprovable' : 'candidate', 'authority' => 'none']),
                'eligibility_version' => $source->eligibility_version + 1,
            ]);
            $this->receipt($source, $currentActor, 'publish', $decision->reason, ['draft_config_id' => $configId, 'current_preview_id' => $id, 'approved_decision_id' => null]);
            return PepDirectoryPreview::on($name)->findOrFail($id);
        });
    }

    /** Discovery is unlocked; every mutation then locks Fuente → source → persisted actor. */
    private function locked(string $name, int $id, User $actor, ConfigurationDecisionData $decision): array
    {
        $binding = PepDirectorySource::on($name)->findOrFail($id)->fuente_id;
        $fuente = $binding === null ? null : Fuente::on($name)->lockForUpdate()->find($binding);
        $source = PepDirectorySource::on($name)->lockForUpdate()->findOrFail($id);
        if ($source->fuente_id !== $binding) {
            throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
        }
        $currentActor = $this->actor($name, $actor);
        $this->require($source->configurable && $fuente !== null);
        if ($source->eligibility_version !== $decision->expectedVersion) {
            throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
        }
        return [$fuente, $source, $currentActor];
    }

    private function actor(string $name, User $actor): User
    {
        $persisted = User::on($name)->lockForUpdate()->find($actor->getKey());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        if ($persisted === null || ! $persisted->activo || ! $persisted->checkPermissionTo('gestionar fuentes')) {
            throw new AuthorizationException('An active user with gestionar fuentes is required.');
        }
        return $persisted;
    }

    private function snapshot(string $name, Fuente $fuente, PepDirectorySource $source, int $captureId): array
    {
        $capture = PepDirectoryCapture::on($name)->where('source_id', $source->id)->findOrFail($captureId);
        $row = $this->row($capture);
        $metadata = $capture->metadata;
        $provenance = $capture->provenance;
        $this->require(hash('sha256', $row['body_bytes']) === $capture->content_sha256 && ($metadata['source_url'] ?? null) === $fuente->url && ($provenance['requested_url'] ?? null) === $fuente->url && ($provenance['final_url'] ?? null) === $fuente->url);
        return ['fuente' => $this->row($fuente), 'source' => $this->row($source), 'capture' => $row, 'metadata' => $metadata,
            'draft' => $source->draft_config_id === null ? null : $this->row(PepDirectoryConfiguration::on($name)->findOrFail($source->draft_config_id)),
            'preview' => $source->current_preview_id === null ? null : $this->row(PepDirectoryPreview::on($name)->findOrFail($source->current_preview_id))];
    }

    private function receipt(PepDirectorySource $source, User $actor, string $action, string $reason, array $projections): void
    {
        $name = $source->getConnectionName();
        PepDirectoryConfigurationDecision::on($name)->insert([
            'source_id' => $source->id, 'sequence' => $source->eligibility_version + 1, 'action' => $action,
            'configuration_id' => $projections['draft_config_id'] ?? $source->draft_config_id,
            'preview_id' => $projections['current_preview_id'] ?? $source->current_preview_id,
            'prior_approval_id' => $source->approved_decision_id, 'new_approval_id' => array_key_exists('approved_decision_id', $projections) ? $projections['approved_decision_id'] : $source->approved_decision_id,
            'actor_kind' => 'human', 'actor_identity' => 'user:'.$actor->id, 'actor_snapshot' => $this->json($this->actorSnapshot($actor)), 'reason' => $reason,
        ]);
        PepDirectorySource::on($name)->whereKey($source->id)->update($projections + ['eligibility_version' => $source->eligibility_version + 1]);
    }

    private function actorSnapshot(User $actor): array
    {
        return ['id' => $actor->id, 'name' => $actor->name];
    }

    /** Convert PostgreSQL bytea streams for exact snapshot comparisons, never regenerate identities. */
    private function row(Model $model): array
    {
        $row = $model->getRawOriginal();
        foreach ($row as &$value) {
            if (is_resource($value)) {
                rewind($value);
                $value = stream_get_contents($value);
            }
        }
        return $row;
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['preview' => 'A consistent registered binding and immutable evidence are required.']);
        }
    }
}
