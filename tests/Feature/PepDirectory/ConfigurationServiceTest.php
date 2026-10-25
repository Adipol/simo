<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

require_once __DIR__.'/../../Support/PepDirectoryConfigurationTestCase.php';

use App\Exceptions\PepDirectory\ConfigurationConflictException;
use App\Models\{PepDirectorySource, PepDirectoryConfiguration, PepDirectoryCapture, PepDirectoryPreview, PepDirectoryConfigurationDecision, User};
use App\Services\PepDirectory\ConfigurationService;
use App\Services\PepDirectory\DTOs\ConfigurationDecisionData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\PepDirectoryConfigurationTestCase;

final class ConfigurationServiceTest extends PepDirectoryConfigurationTestCase
{
    private function actor(): User
    {
        $id = $this->db->table('users')->insertGetId(['label' => 'synthetic-c1', 'name' => 'Persisted configurator', 'activo' => true]);
        $actor = User::findOrFail($id);
        $actor->givePermissionTo(Permission::firstOrCreate(['name' => 'gestionar fuentes', 'guard_name' => 'web']));

        return $actor;
    }

    private function candidate(): array
    {
        $f = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PepDirectory/configuration-service/candidate.json'), true, 512, JSON_THROW_ON_ERROR);
        $fuente = $this->db->table('fuentes')->insertGetId(['label' => 'Synthetic C1', 'url' => $f['declaration']['url'], 'tipo' => 'directorio']);
        $source = $this->db->table('pep_directory_sources')->insertGetId(['identity' => 'synthetic-c1', 'fuente_id' => $fuente, 'configurable' => true, 'version' => 7]);
        $config = $this->db->table('pep_directory_configurations')->insertGetId(['source_id' => $source, 'declaration' => json_encode($f['declaration']), 'canonical_bytes' => $f['canonical'], 'revision' => $f['revision'], 'binding_url' => $f['declaration']['url'], 'directory_url' => $f['declaration']['url'], 'creator_identity' => 'synthetic', 'creator_snapshot' => '{}']);
        $capture = $this->db->table('pep_directory_captures')->insertGetId(['source_id' => $source, 'body_bytes' => $f['body'], 'content_sha256' => $f['content_sha256'], 'metadata' => json_encode($f['metadata']), 'provenance' => '{}', 'captured_at' => $f['metadata']['observed_at']]);
        $preview = $this->db->table('pep_directory_previews')->insertGetId(['source_id' => $source, 'configuration_id' => $config, 'capture_id' => $capture, 'result_bytes' => $f['result'], 'result_sha256' => $f['result_sha256'], 'wire_payload' => json_encode($f['wire']), 'validity' => 'incomplete', 'qualification' => '{}', 'eligibility_version' => 0]);
        $baseline = $this->db->table('pep_directory_observations')->insertGetId(['source_id' => $source, 'submission_id' => 'c1', 'capture_identity' => 'c1', 'content_sha256' => $f['content_sha256'], 'provenance' => '{}', 'payload' => json_encode($f['wire']), 'observed_at' => $f['metadata']['observed_at'], 'disposition' => 'baseline', 'reasons' => '[]']);
        $this->db->table('pep_directory_sources')->where('id', $source)->update(['draft_config_id' => $config, 'current_preview_id' => $preview, 'baseline_observation_id' => $baseline]);

        return compact('source', 'fuente', 'config', 'capture', 'preview', 'baseline');
    }

    private function decision(int $version = 0): ConfigurationDecisionData
    {
        return ConfigurationDecisionData::fromArray(['expected_version' => $version, 'reason' => 'Synthetic associations reviewed: á']);
    }

    private function rejected(string $exception, callable $operation): void
    {
        $before = [];
        foreach (['sources', 'configuration_decisions', 'events', 'observations', 'reviews'] as $table) {
            $before[$table] = $this->db->table('pep_directory_'.$table)->orderBy('id')->get()->toJson();
        }
        try {
            $operation();
            self::fail('Expected rejection '.$exception);
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
        }
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $this->db->table('pep_directory_'.$table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_public_approval_and_revocation_preserve_baseline_and_append_server_audit(): void
    {
        self::assertTrue(class_exists(ConfigurationService::class), 'Stored-preview approval/revocation service must exist.');
        $c = $this->candidate();
        $actor = $this->actor();
        $actor->name = 'Forged';
        $actor->setConnection('untrusted');
        $service = new ConfigurationService();
        $before = PepDirectorySource::findOrFail($c['source'])->load('observations', 'events');
        $writes = [];
        $reviews = $this->db->table('pep_directory_reviews')->orderBy('id')->get()->toJson();
        $this->db->listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $audit = $service->approve($c['source'], $c['preview'], $actor, $this->decision());
        self::assertSame('approve', $audit->action);
        self::assertSame(1, $audit->sequence);
        self::assertSame($c['config'], $audit->configuration_id);
        self::assertSame($c['preview'], $audit->preview_id);
        self::assertSame('human', $audit->actor_kind);
        self::assertSame('user:'.$actor->id, $audit->actor_identity);
        self::assertSame(['id' => $actor->id, 'name' => 'Persisted configurator'], $audit->actor_snapshot);
        self::assertSame('Synthetic associations reviewed: á', $audit->reason);
        self::assertNotNull($audit->decided_at);
        self::assertNull($audit->prior_approval_id);
        $approved = $before->fresh();
        self::assertSame($audit->id, $approved->approved_decision_id);
        self::assertSame($audit->id, $audit->new_approval_id);
        self::assertSame($c['preview'], $approved->current_preview_id);
        self::assertSame($audit->id, $approved->approvedDecision->id);
        $originalAudit = $audit->getRawOriginal();
        $this->rejected(ConfigurationConflictException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision()));
        $revoked = $service->revoke($c['source'], $actor, $this->decision(1));
        self::assertSame('revoke', $revoked->action);
        self::assertSame(2, $revoked->sequence);
        self::assertSame($audit->id, $revoked->prior_approval_id);
        self::assertNull($revoked->new_approval_id);
        self::assertNull($approved->fresh()->approved_decision_id);
        self::assertNull($approved->fresh()->current_preview_id);
        self::assertSame($originalAudit, $audit->fresh()->getRawOriginal());
        $this->rejected(ConfigurationConflictException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision(2)));
        $again = $service->revoke($c['source'], $actor, $this->decision(2));
        self::assertSame(3, $again->sequence);
        $after = $before->fresh()->load('observations', 'events', 'configurationDecisions');
        self::assertCount(3, $after->configurationDecisions);
        foreach ($after->configurationDecisions as $index => $receipt) {
            self::assertSame($index + 1, $receipt->sequence);
            self::assertSame($c['source'], $receipt->source_id);
            self::assertSame('human', $receipt->actor_kind);
            self::assertSame('user:'.$actor->id, $receipt->actor_identity);
            self::assertSame(['id' => $actor->id, 'name' => 'Persisted configurator'], $receipt->actor_snapshot);
            self::assertSame('Synthetic associations reviewed: á', $receipt->reason);
            self::assertSame($this->db->selectOne('SELECT CURRENT_TIMESTAMP::text AS time')->time, $receipt->getRawOriginal('decided_at'));
        }
        self::assertCount(6, $writes);
        foreach ($writes as $index => $sql) {
            self::assertStringStartsWith($index % 2 === 0 ? 'insert into "pep_directory_configuration_decisions"' : 'update "pep_directory_sources"', $sql);
            self::assertStringNotContainsString('"decided_at"', $sql);
        }
        self::assertSame($reviews, $this->db->table('pep_directory_reviews')->orderBy('id')->get()->toJson());
        self::assertSame(7, $after->version);
        self::assertSame($c['baseline'], $after->baseline_observation_id);
        self::assertSame($before->observations->toArray(), $after->observations->toArray());
        self::assertSame($before->events->toArray(), $after->events->toArray());
        self::assertSame($c['config'], $after->draftConfiguration->id);
        self::assertSame($c['capture'], PepDirectoryPreview::findOrFail($c['preview'])->capture->id);
        self::assertSame($c['config'], PepDirectoryPreview::findOrFail($c['preview'])->configuration->id);
    }

    public function test_reapproval_requires_new_preview_generation_and_new_lineage(): void
    {
        $c = $this->candidate();
        $actor = $this->actor();
        $service = new ConfigurationService();
        $first = $service->approve($c['source'], $c['preview'], $actor, $this->decision());
        $this->rejected(ConfigurationConflictException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision(1)));
        // Fixture represents a future trusted bridge, not a public lifecycle/ingestion API.
        $f = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PepDirectory/configuration-service/candidate.json'), true, 512, JSON_THROW_ON_ERROR);
        $preview = $this->db->table('pep_directory_previews')->insertGetId(['source_id' => $c['source'], 'configuration_id' => $c['config'], 'capture_id' => $c['capture'], 'result_bytes' => $f['result'], 'result_sha256' => $f['result_sha256'], 'wire_payload' => json_encode($f['wire']), 'validity' => 'incomplete', 'qualification' => '{}', 'eligibility_version' => 1]);
        $this->db->table('pep_directory_sources')->where('id', $c['source'])->update(['current_preview_id' => $preview]);
        $second = $service->approve($c['source'], $preview, $actor, $this->decision(1));
        self::assertNotSame($first->id, $second->id);
        self::assertSame($first->id, $second->prior_approval_id);
        self::assertSame($second->id, $second->new_approval_id);
        self::assertSame($second->id, PepDirectorySource::findOrFail($c['source'])->approved_decision_id);
        self::assertSame(2, $second->sequence);
    }

    public function test_stored_integrity_and_binding_rejections_have_no_decision_effects(): void
    {
        $c = $this->candidate();
        $actor = $this->actor();
        $service = new ConfigurationService();
        $other = $this->db->table('pep_directory_sources')->insertGetId(['identity' => 'other-c1', 'configurable' => true]);
        $this->rejected(ConfigurationConflictException::class, fn () => $service->approve($other, $c['preview'], $actor, $this->decision()));
        $changes = [
            ['sources', $c['source'], ['configurable' => false]],
            ['sources', $c['source'], ['draft_config_id' => null]],
            ['sources', $c['source'], ['fuente_id' => null]],
            ['previews', $c['preview'], ['eligibility_version' => 1]],
            ['previews', $c['preview'], ['validity' => 'failed']],
            ['previews', $c['preview'], ['result_sha256' => str_repeat('a', 64)]],
            ['previews', $c['preview'], ['result_bytes' => 'invalid json']],
            ['previews', $c['preview'], ['wire_payload' => '{}']],
            ['captures', $c['capture'], ['content_sha256' => str_repeat('b', 64)]],
            ['captures', $c['capture'], ['body_bytes' => 'corrupted']],
            ['captures', $c['capture'], ['metadata' => '{}']],
            ['configurations', $c['config'], ['revision' => 'wrong']],
            ['configurations', $c['config'], ['canonical_bytes' => '{}']],
            ['configurations', $c['config'], ['declaration' => '{}']],
            ['configurations', $c['config'], ['binding_url' => 'https://example.invalid/other']],
            ['configurations', $c['config'], ['directory_url' => 'https://example.invalid/other']],
        ];
        foreach ($changes as [$table, $id, $change]) {
            $this->db->beginTransaction();
            try {
                $this->db->table('pep_directory_'.$table)->where('id', $id)->update($change);
                $exception = $table === 'previews' && isset($change['eligibility_version']) ? ConfigurationConflictException::class : ValidationException::class;
                $this->rejected($exception, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision()));
            } finally {
                $this->db->rollBack();
            }
        }
        // Self-consistent hashes do not make empty/failed parsing a qualified preview.
        foreach (['empty', 'failed', 'valid', 'incomplete'] as $validity) {
            $this->db->beginTransaction();
            try {
                $wire = PepDirectoryPreview::findOrFail($c['preview'])->wire_payload;
                $wire['validity'] = $validity;
                $wire['positions'] = [];
                $bytes = json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $this->db->table('pep_directory_previews')->where('id', $c['preview'])->update(['wire_payload' => $bytes, 'result_bytes' => $bytes, 'result_sha256' => hash('sha256', $bytes), 'validity' => $validity]);
                $this->rejected(ValidationException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision()));
            } finally {
                $this->db->rollBack();
            }
        }
        foreach ([['activo' => false], ['url' => 'https://example.invalid/changed']] as $change) {
            $this->db->beginTransaction();
            try {
                $this->db->table('fuentes')->where('id', $c['fuente'])->update($change);
                $this->rejected(ConfigurationConflictException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision(1)));
            } finally {
                $this->db->rollBack();
            }
        }
        // An inactive live binding is denied even if a synthetic fixture reinstates pointers.
        $this->db->table('fuentes')->where('id', $c['fuente'])->update(['activo' => false]);
        $this->db->table('pep_directory_sources')->where('id', $c['source'])->update(['current_preview_id' => $c['preview']]);
        $this->db->table('pep_directory_previews')->where('id', $c['preview'])->update(['eligibility_version' => 1]);
        $this->rejected(ValidationException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision(1)));
    }

    public function test_persisted_actor_denies_inactive_missing_and_revoked_permission_despite_forgery(): void
    {
        $c = $this->candidate();
        $actor = $this->actor();
        $actor->load('permissions', 'roles');
        $actor->activo = true;
        $actor->name = 'Forged';
        $service = new ConfigurationService();
        foreach (['inactive', 'missing', 'revoked'] as $state) {
            $this->db->beginTransaction();
            try {
                if ($state === 'inactive') {
                    $this->db->table('users')->where('id', $actor->id)->update(['activo' => false]);
                } elseif ($state === 'missing') {
                    $this->db->table('users')->where('id', $actor->id)->delete();
                } else {
                    $this->db->table('model_has_permissions')->where('model_id', $actor->id)->delete();
                }
                $this->rejected(AuthorizationException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision()));
                $this->rejected(AuthorizationException::class, fn () => $service->revoke($c['source'], $actor, $this->decision()));
            } finally {
                $this->db->rollBack();
            }
        }
    }

    public function test_pending_inactive_and_orphan_revocation_and_stale_retry(): void
    {
        $c = $this->candidate();
        $actor = $this->actor();
        $service = new ConfigurationService();
        $pending = $service->revoke($c['source'], $actor, $this->decision());
        self::assertSame(1, $pending->sequence);
        self::assertNull($pending->prior_approval_id);
        self::assertSame($c['preview'], $pending->preview_id);
        $this->db->table('fuentes')->where('id', $c['fuente'])->update(['activo' => false]);
        $inactive = $service->revoke($c['source'], $actor, $this->decision(2));
        self::assertSame(3, $inactive->sequence);
        $this->db->table('fuentes')->where('id', $c['fuente'])->delete();
        $orphan = $service->revoke($c['source'], $actor, $this->decision(4));
        self::assertSame(5, $orphan->sequence);
        $source = PepDirectorySource::findOrFail($c['source']);
        self::assertNull($source->fuente_id);
        self::assertNull($source->current_preview_id);
        self::assertNull($source->approved_decision_id);
        self::assertSame(7, $source->version);
        self::assertSame($c['baseline'], $source->baseline_observation_id);
        self::assertCount(5, $source->configurationDecisions);
        $this->rejected(ConfigurationConflictException::class, fn () => $service->revoke($c['source'], $actor, $this->decision(4)));
    }

    public function test_lock_order_binding_revalidation_and_faults_roll_back_atomically(): void
    {
        $c = $this->candidate();
        $actor = $this->actor();
        $service = new ConfigurationService();
        $locks = [];
        $this->db->listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'for update')) {
                $locks[] = $query->sql;
            }
        });
        // Fault callback fires before SQL, avoiding an aborted outer PostgreSQL transaction.
        foreach (['insert into "pep_directory_configuration_decisions"', 'update "pep_directory_sources"'] as $needle) {
            $fault = (object) ['armed' => true];
            $this->db->beforeExecuting(static function (string $sql) use ($fault, $needle): void {
                if ($fault->armed && str_starts_with($sql, $needle)) {
                    $fault->armed = false;
                    throw new \RuntimeException('Synthetic atomic fault');
                }
            });
            $locks = [];
            $this->rejected(\RuntimeException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision()));
            self::assertCount(3, $locks);
            foreach (['fuentes', 'pep_directory_sources', 'users'] as $index => $table) {
                self::assertStringContainsString('"'.$table.'"', $locks[$index]);
            }
            self::assertSame(1, $this->db->transactionLevel());
        }
        $otherFuente = $this->db->table('fuentes')->insertGetId(['label' => 'Synthetic other binding']);
        $fault = (object) ['armed' => true];
        $this->db->beforeExecuting(function (string $sql) use ($fault, $c, $otherFuente): void {
            if ($fault->armed && str_starts_with($sql, 'select * from "pep_directory_sources"') && str_contains($sql, 'for update')) {
                $fault->armed = false;
                $this->db->table('pep_directory_sources')->where('id', $c['source'])->update(['fuente_id' => $otherFuente]);
            }
        });
        $locks = [];
        $this->rejected(ConfigurationConflictException::class, fn () => $service->approve($c['source'], $c['preview'], $actor, $this->decision()));
        self::assertCount(2, $locks, 'No different Fuente or actor lock after binding conflict.');
        $service->approve($c['source'], $c['preview'], $actor, $this->decision());
        $fault = (object) ['armed' => true];
        $this->db->beforeExecuting(static function (string $sql) use ($fault): void {
            if ($fault->armed && str_starts_with($sql, 'update "pep_directory_sources"')) {
                $fault->armed = false;
                throw new \RuntimeException('Synthetic revocation fault');
            }
        });
        $this->rejected(\RuntimeException::class, fn () => $service->revoke($c['source'], $actor, $this->decision(1)));
        self::assertSame(1, $this->db->transactionLevel());
    }

    public function test_read_models_refuse_instance_mutation_and_legacy_read_path_is_unchanged(): void
    {
        $c = $this->candidate();
        $audit = (new ConfigurationService())->approve($c['source'], $c['preview'], $this->actor(), $this->decision());
        foreach ([PepDirectoryConfiguration::findOrFail($c['config']), PepDirectoryCapture::findOrFail($c['capture']), PepDirectoryPreview::findOrFail($c['preview']), $audit, PepDirectorySource::findOrFail($c['source'])] as $model) {
            foreach (['save', 'saveQuietly', 'delete', 'deleteQuietly'] as $method) {
                $this->rejected(\LogicException::class, fn () => $model->$method());
            }
            $this->rejected(\Illuminate\Database\Eloquent\MassAssignmentException::class, fn () => $model->update(['reason' => 'forged']));
        }
        $legacy = PepDirectorySource::withHolderHistory()->where('configurable', false)->firstOrFail();
        self::assertTrue($legacy->relationLoaded('observations'));
        self::assertTrue($legacy->relationLoaded('baselineObservation'));
        self::assertIsInt($legacy->version);
        self::assertFalse($legacy->configurable);
        self::assertSame(0, $legacy->eligibility_version);
        self::assertNull($legacy->approved_decision_id);
    }

    public function test_decision_boundary_rejects_protected_fields_and_malformed_input(): void
    {
        self::assertTrue(class_exists(ConfigurationDecisionData::class), 'Typed decision must validate only version and reason.');
        foreach ([['expected_version' => '0'], ['expected_version' => -1], ['expected_version' => PHP_INT_MAX], ['reason' => ' '], ['reason' => "\xff"], ['reason' => "a\0b"], ['reason' => str_repeat('a', 10001)], ['reason' => []], ['actor' => 1], ['approved' => true], ['qualification' => true], ['configuration' => []], ['result' => []], ['inventory' => []], ['decided_at' => 'now']] as $change) {
            $this->rejected(ValidationException::class, fn () => ConfigurationDecisionData::fromArray(array_replace(['expected_version' => 0, 'reason' => 'reviewed'], $change)));
        }
    }
}
