<?php

declare(strict_types=1);

namespace App\Services\PepDirectory;

use App\Exceptions\PepDirectory\ConfigurationConflictException;
use App\Models\{Fuente, PepDirectoryConfigurationDecision, PepDirectoryPreview, PepDirectorySource, User};
use App\Services\PepDirectory\DTOs\ConfigurationDecisionData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** Decisions over trusted, immutable stored previews only; no ingestion or runtime authority. */
final class ConfigurationService
{
    public function approve(int $sourceId, int $previewId, User $actor, ConfigurationDecisionData $decision): PepDirectoryConfigurationDecision
    {
        $this->require($previewId > 0);

        return $this->decide($sourceId, $previewId, $actor, $decision);
    }

    public function revoke(int $sourceId, User $actor, ConfigurationDecisionData $decision): PepDirectoryConfigurationDecision
    {
        return $this->decide($sourceId, null, $actor, $decision);
    }

    private function decide(int $sourceId, ?int $previewId, User $actor, ConfigurationDecisionData $decision): PepDirectoryConfigurationDecision
    {
        $this->require($sourceId > 0);
        $connection = (new PepDirectorySource())->getConnection();

        return $connection->transaction(function () use ($connection, $sourceId, $previewId, $actor, $decision): PepDirectoryConfigurationDecision {
            $name = $connection->getName();
            $binding = PepDirectorySource::on($name)->findOrFail($sourceId)->fuente_id;
            // Never acquire a different Fuente lock after taking the source lock.
            $fuente = $binding === null ? null : Fuente::on($name)->lockForUpdate()->find($binding);
            $source = PepDirectorySource::on($name)->lockForUpdate()->findOrFail($sourceId);
            if ($source->fuente_id !== $binding) {
                throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
            }
            $currentActor = User::on($name)->lockForUpdate()->find($actor->getKey());
            // Bounded nontransactional cache side effect, matching ReviewService freshness.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            if ($currentActor === null || ! $currentActor->activo || ! $currentActor->checkPermissionTo('gestionar fuentes')) {
                throw new AuthorizationException('An active user with gestionar fuentes is required.');
            }
            $this->require($source->configurable);
            if ($source->eligibility_version !== $decision->expectedVersion) {
                throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
            }
            if ($previewId !== null) {
                if ($source->current_preview_id !== $previewId) {
                    throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
                }
                $this->require($fuente !== null && $fuente->activo);
                $preview = $source->currentPreview()->with(['configuration', 'capture'])->firstOrFail();
                if ($preview->eligibility_version !== $source->eligibility_version) {
                    throw new ConfigurationConflictException($decision->expectedVersion, $source->eligibility_version);
                }
                $this->require($source->draft_config_id === $preview->configuration_id);
                $this->validateStored($source, $preview, $fuente->url);
            }
            $version = $source->eligibility_version + 1;
            // Reserve a PostgreSQL-generated id to insert a complete immutable self-referencing receipt.
            // Sequence allocation is deliberately not transactional; never derives an id from a caller.
            $id = (int) $connection->selectOne("SELECT nextval(pg_get_serial_sequence('pep_directory_configuration_decisions', 'id')) AS id")->id;
            PepDirectoryConfigurationDecision::on($name)->insert([
                'id' => $id, 'source_id' => $sourceId, 'sequence' => $version, 'action' => $previewId === null ? 'revoke' : 'approve',
                'configuration_id' => $source->draft_config_id, 'preview_id' => $previewId ?? $source->current_preview_id,
                'prior_approval_id' => $source->approved_decision_id, 'new_approval_id' => $previewId === null ? null : $id,
                'actor_kind' => 'human', 'actor_identity' => 'user:'.$currentActor->id,
                'actor_snapshot' => json_encode(['id' => $currentActor->id, 'name' => $currentActor->name], JSON_THROW_ON_ERROR),
                'reason' => $decision->reason,
                // decided_at uses the database server default.
            ]);
            PepDirectorySource::on($name)->whereKey($sourceId)->update([
                'eligibility_version' => $version, 'approved_decision_id' => $previewId === null ? null : $id,
                'current_preview_id' => $previewId,
            ]);

            return PepDirectoryConfigurationDecision::on($name)->findOrFail($id);
        });
    }

    private function validateStored(PepDirectorySource $source, PepDirectoryPreview $preview, ?string $url): void
    {
        $config = $preview->configuration;
        $capture = $preview->capture;
        $this->require($config !== null && $capture !== null && $config->source_id === $source->id
            && $capture->source_id === $source->id && $preview->source_id === $source->id);
        $this->require($url !== null && $config->binding_url === $url && $config->directory_url === $url);
        $canonical = $this->bytes($config->canonical_bytes);
        $this->require($config->revision === 'configured-html-v1:'.hash('sha256', $canonical));
        $identity = $this->json($canonical);
        $this->require(($identity['contract'] ?? null) === 'configured-html-v1');
        $storedConfig = $identity['config'] ?? [];
        $declaration = $config->declaration;
        $this->require(is_array($storedConfig) && is_array($declaration));
        foreach (['source' => $source->identity, 'url' => $url] as $key => $value) {
            $this->require(($storedConfig[$key] ?? null) === $value && ($declaration[$key] ?? null) === $value);
        }
        // Compare stored representations, not a PHP compiler or recreation of Python canonical JSON.
        foreach (['directory', 'record', 'section', 'columns'] as $key) {
            $this->require(($storedConfig[$key] ?? null) === ($declaration[$key] ?? null));
        }
        $fields = $storedConfig['fields'] ?? [];
        $this->require(is_array($fields) && count($fields) === count($declaration['fields'] ?? []));
        foreach ($fields as $pair) {
            $this->require(is_array($pair) && count($pair) === 2 && isset($declaration['fields'][$pair[0]]) && is_array($pair[1]));
            $field = $declaration['fields'][$pair[0]];
            foreach (['scope', 'selector', 'column', 'constant'] as $key) {
                $this->require(($pair[1][$key] ?? null) === ($field[$key] ?? ($key === 'scope' ? 'record' : null)));
            }
        }
        $body = $this->bytes($capture->body_bytes);
        $result = $this->bytes($preview->result_bytes);
        $this->require(hash('sha256', $body) === $capture->content_sha256 && hash('sha256', $result) === $preview->result_sha256);
        $wire = $this->json($result);
        $this->require($this->sameJson($wire, $preview->wire_payload) && $this->keys($wire, ['source', 'revision', 'observed_at', 'validity', 'positions', 'evidence']));
        $metadata = $capture->metadata;
        $this->require(is_array($metadata) && ($metadata['status'] ?? null) === 200 && ($metadata['source_url'] ?? null) === $url
            && ($wire['source'] ?? null) === $source->identity && ($wire['revision'] ?? null) === $config->revision
            && ($metadata['observed_at'] ?? null) === ($wire['observed_at'] ?? null)
            && $preview->validity === $wire['validity'] && in_array($wire['validity'], ['valid', 'incomplete'], true));
        try {
            $date = new \DateTimeImmutable($wire['observed_at']);
            $this->require(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $wire['observed_at']) === 1 && $date == $capture->captured_at);
            $encoding = $metadata['encoding'] ?? '';
            $this->require(is_string($encoding) && $encoding !== '' && mb_check_encoding($body, $encoding));
            $this->require(mb_convert_encoding($body, 'UTF-8', $encoding) === $wire['evidence']);
        } catch (\ValueError|\Exception $e) {
            throw ValidationException::withMessages(['preview' => ['Invalid stored capture identity.']]);
        }
        // Actual extractor contract: structural rejection has NO positions. A complete unreviewed
        // parse retains positions with INCOMPLETE; VALID alone never supplies qualification authority.
        $positions = $wire['positions'];
        $this->require(is_array($positions) && array_is_list($positions) && count($positions) > 0 && count($positions) <= 1000);
        foreach ($positions as $position) {
            $this->require(is_array($position) && $this->keys($position, ['unit', 'role', 'person', 'condition', 'evidence']));
            foreach (['unit', 'role', 'person', 'evidence'] as $field) {
                $this->require(is_string($position[$field]) && trim($position[$field]) !== '' && ! str_contains($position[$field], "\0"));
            }
            $this->require($position['condition'] === null || (is_string($position['condition']) && trim($position['condition']) !== ''));
        }
    }

    private function sameJson(array $left, array $right): bool
    {
        if (count($left) !== count($right) || array_is_list($left) !== array_is_list($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || (is_array($value)
                ? (! is_array($right[$key]) || ! $this->sameJson($value, $right[$key])) : $value !== $right[$key])) {
                return false;
            }
        }

        return true;
    }

    private function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    /** @param resource|string $value PDO bytea can be a stream. */
    private function bytes(mixed $value): string
    {
        if (is_resource($value)) {
            rewind($value);
            $value = stream_get_contents($value);
        }
        $this->require(is_string($value));

        return $value;
    }

    private function json(string $bytes): array
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationException::withMessages(['preview' => ['Invalid stored JSON bytes.']]);
        }
        $this->require(is_array($value));

        return $value;
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['preview' => ['A consistent stored structural preview and registered binding are required.']]);
        }
    }
}
